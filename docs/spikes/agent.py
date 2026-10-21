import json, time, urllib.request, urllib.error
from concurrent.futures import ThreadPoolExecutor
from q import get as qget, search as qsearch, ENV
AKEY, MODEL = ENV["AI_PROVIDER_KEY"], ENV["AI_MODEL"]
SIGNAL={'artist','tv_show','movie','book','place','brand','podcast','video_game'}; LOC={'locality','destination'}
import os, copy
CACHEON=os.environ.get("CACHE")=="1"
USAGE={"in":0,"out":0,"calls":0,"qloo":0,"cache_read":0,"cache_write":0}
CACHE={}
def cached(key,fn):
    if key in CACHE: return CACHE[key]
    USAGE["qloo"]+=1; CACHE[key]=fn(); return CACHE[key]
def t_search(text):
    def f():
        out=[]
        for r in qsearch(text).get("results",[]):
            ty=[t.split(":")[-1] for t in r["types"]]
            t=next((x for x in [*SIGNAL,*LOC] if x in ty),None)
            if t: out.append({"id":r["entity_id"],"name":r["name"],"type":t,"kind":"location" if t in LOC else "entity"})
        return out[:8]
    return cached(("s",text),f)
def t_artists(entity_ids=None,location=None,take=15):
    p={"filter.type":"urn:entity:artist","take":take}
    if entity_ids: p["signal.interests.entities"]=",".join(sorted(set(entity_ids)))
    if location: p["signal.location.query"]=location
    def f():
        d=qget("/v2/insights",**p)
        if "error" in d: return {"error":d["body"][:200]}
        return [{"id":e["entity_id"],"name":e["name"],"songs":(e["properties"].get("notable_songs") or [])[:5]} for e in d["results"]["entities"]]
    return cached(("a",json.dumps(p,sort_keys=True)),f)
IMPL={}; TOOLS=[]
def claude(messages,system,tools=None,max_tokens=16000):
    if CACHEON and tools:
        messages=copy.deepcopy(messages); users=[m for m in messages if m["role"]=="user"]
        for m in users[-2:]:
            if isinstance(m["content"],str): m["content"]=[{"type":"text","text":m["content"]}]
            m["content"][-1]["cache_control"]={"type":"ephemeral"}
    body={"model":MODEL,"max_tokens":max_tokens,"system":system,"messages":messages}
    if tools: body["tools"]=tools
    for attempt in range(4):
        req=urllib.request.Request("https://api.anthropic.com/v1/messages",json.dumps(body).encode(),{"x-api-key":AKEY,"anthropic-version":"2023-06-01","content-type":"application/json"})
        try:
            with urllib.request.urlopen(req,timeout=240) as f: d=json.load(f)
            USAGE["calls"]+=1; USAGE["in"]+=d["usage"]["input_tokens"]; USAGE["out"]+=d["usage"]["output_tokens"]; USAGE["cache_read"]+=d["usage"].get("cache_read_input_tokens",0); USAGE["cache_write"]+=d["usage"].get("cache_creation_input_tokens",0); return d
        except urllib.error.HTTPError as e:
            msg=e.read().decode()[:400]
            if e.code in(429,500,529,503) and attempt<3: time.sleep(3*(attempt+1)); continue
            raise RuntimeError(f"{e.code} {msg}")
def run(system,user,tools=True,max_turns=40):
    msgs=[{"role":"user","content":user}]; trace=[]
    for turn in range(max_turns):
        d=claude(msgs,system,TOOLS if tools else None)
        msgs.append({"role":"assistant","content":d["content"]})
        uses=[b for b in d["content"] if b["type"]=="tool_use"]
        if not uses or d["stop_reason"]!="tool_use":
            return "".join(b.get("text","") for b in d["content"] if b["type"]=="text"),trace
        def ex(b):
            try: return b,IMPL[b["name"]](b["input"])
            except Exception as e: return b,{"error":str(e)[:200]}
        with ThreadPoolExecutor(3) as pool: res=list(pool.map(ex,uses))
        trace+=[{"tool":b["name"],"input":b["input"],"result":r} for b,r in res]
        msgs.append({"role":"user","content":[{"type":"tool_result","tool_use_id":b["id"],"content":json.dumps(r,ensure_ascii=False)[:6000]} for b,r in res]})
    return "(max turns)",trace

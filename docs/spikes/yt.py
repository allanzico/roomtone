import json, re, time, urllib.parse, urllib.request, urllib.error
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"
HDR={"User-Agent":UA,"Accept-Language":"en-US,en;q=0.9","Cookie":"CONSENT=YES+1; SOCS=CAI"}
def fetch(url,timeout=30):
    r=urllib.request.Request(url,headers=HDR)
    with urllib.request.urlopen(r,timeout=timeout) as f: return f.read().decode("utf-8","replace")
def search(q):
    html=fetch("https://www.youtube.com/results?search_query="+urllib.parse.quote(q)+"&sp=EgIQAQ%253D%253D")
    m=re.search(r"var ytInitialData = (\{.*?\});</script>",html,re.S)
    if not m: return None
    d=json.loads(m.group(1)); out=[]
    def walk(o):
        if isinstance(o,dict):
            if "videoRenderer" in o:
                v=o["videoRenderer"]
                try: out.append({"id":v["videoId"],"title":"".join(r["text"] for r in v["title"]["runs"]),"channel":"".join(r["text"] for r in v["ownerText"]["runs"]),"length":v.get("lengthText",{}).get("simpleText"),"views":v.get("viewCountText",{}).get("simpleText")})
                except KeyError: pass
            for x in o.values(): walk(x)
        elif isinstance(o,list):
            for x in o: walk(x)
    walk(d); return out
def player(vid):
    html=fetch("https://www.youtube.com/watch?v="+vid)
    m=re.search(r"var ytInitialPlayerResponse = (\{.*?\});(?:var |</script>)",html,re.S)
    if not m: return None
    d=json.loads(m.group(1)); ps=d.get("playabilityStatus",{})
    return {"status":ps.get("status"),"reason":ps.get("reason"),"embed":ps.get("playableInEmbed"),"secs":int(d.get("videoDetails",{}).get("lengthSeconds",0) or 0)}
def oembed(vid):
    try:
        fetch("https://www.youtube.com/oembed?format=json&url=https://www.youtube.com/watch?v="+vid); return 200
    except urllib.error.HTTPError as e: return e.code

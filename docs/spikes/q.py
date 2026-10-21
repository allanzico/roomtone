import json, time, urllib.parse, urllib.request, urllib.error
from pathlib import Path
RL=[0]
H = "https://hackathon.api.qloo.com"
ENV = dict(l.rstrip("\n").split("=",1) for l in open(Path(__file__).resolve().parents[2] / ".env") if "=" in l and not l.startswith("#"))
K = ENV["QLOO_API_KEY"]
def get(path, **p):
    q = urllib.parse.urlencode({k:v for k,v in p.items() if v is not None}, safe=":,")
    r = urllib.request.Request(f"{H}{path}?{q}", headers={"X-Api-Key": K})
    t=time.time()
    for attempt in range(6):
        try:
            with urllib.request.urlopen(r, timeout=60) as f: d=json.load(f)
        except urllib.error.HTTPError as e:
            d={"error":e.code,"body":e.read().decode()[:300]}
            if e.code==429: RL[0]+=1; time.sleep(1.0*(attempt+1)); continue
        break
    d["_ms"]=int((time.time()-t)*1000); return d
def search(text, n=3, types=None): return get("/search", query=text, limit=n, types=types)

// Capture-only fixture TLS boundary. Never logs credentials or request bodies.
const fs=require('node:fs');
const https=require('node:https');
const http=require('node:http');
const path=require('node:path');
const root=path.resolve(process.argv[2]);
if(!fs.existsSync(path.join(root,'FICTIONAL-ONLY')) || !fs.existsSync(path.join(root,'private.json'))) throw Error('Fresh fixture required');
https.createServer({key:fs.readFileSync(path.join(root,'server-key.private.pem')),cert:fs.readFileSync(path.join(root,'server.pem')),minVersion:'TLSv1.2'},(req,res)=>{
    const url=new URL(req.url,'https://fixture.example.invalid');
    if(req.headers.host!=='fixture.example.invalid' || !/^\/wp-json\/wc\/v3\/(orders(?:\/|$)|wphub-documents\/)/.test(url.pathname)) {res.writeHead(404).end();return;}
    const upstream=http.request({hostname:'127.0.0.1',port:8802,path:req.url,method:req.method,headers:{...req.headers,host:'fixture.example.invalid'}},incoming=>{
        let chunks=[]; incoming.on('data',chunk=>chunks.push(chunk)); incoming.on('end',()=>{
            let status=incoming.statusCode, body=Buffer.concat(chunks), fault=false;
            const faultPath=path.join(root,'fault-once.json');
            if(fs.existsSync(faultPath)) {
                const spec=JSON.parse(fs.readFileSync(faultPath,'utf8'));
                if(spec.path===url.pathname && req.method==='POST' && status===200) {
                    fs.unlinkSync(faultPath); status=503; body=Buffer.from('{"fixture":"response deliberately lost after real store commit"}'); fault=true;
                }
            }
            fs.appendFileSync(path.join(root,'tls-requests.jsonl'),JSON.stringify({method:req.method,path:url.pathname,upstream_status:incoming.statusCode,status,fault,tls:req.socket.getProtocol()})+'\n');
            res.writeHead(status,{'Content-Type':'application/json','Cache-Control':'no-store'});res.end(body);
        });
    });
    upstream.on('error',()=>res.writeHead(502).end());req.pipe(upstream);
}).listen(8443,'127.0.0.1',()=>console.log('Fictional fixture TLS proxy listening on loopback8443'));

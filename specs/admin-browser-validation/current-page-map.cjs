const fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../../wallet-ecosystem/apps/admin');
const ts=require(path.join(root,'node_modules/typescript'));
const routes=JSON.parse(fs.readFileSync(__dirname+'/current-inventory.json','utf8')).routes;
const walk=d=>fs.readdirSync(d,{withFileTypes:true}).flatMap(e=>e.isDirectory()?walk(path.join(d,e.name)):[path.join(d,e.name)]);
const files=[...walk(root+'/src'),...walk(root+'/lib'),...walk(root+'/app')].filter(f=>/\.tsx?$/.test(f));
const graph=new Map();
for(const file of files){
 const source=ts.createSourceFile(file,fs.readFileSync(file,'utf8'),ts.ScriptTarget.Latest,true);
 const imports=[],endpoints=[];
 function value(n){
  if(!n)return '';
  if(ts.isStringLiteralLike(n))return n.text;
  if(ts.isTemplateExpression(n))return n.head.text+n.templateSpans.map(s=>value(s.expression)+s.literal.text).join('');
  if(ts.isBinaryExpression(n)&&n.operatorToken.kind===ts.SyntaxKind.PlusToken)return value(n.left)+value(n.right);
  const text=n.getText(source);
  if(text==='AdminAppClient.BASE')return '/v1/admin-app';
  if(/^(qs|query|params|searchParams)$/.test(text)||ts.isConditionalExpression(n))return '';
  if(ts.isCallExpression(n)&&n.expression.getText(source)==='encodeURIComponent')return value(n.arguments[0]);
  return '{'+text.replace(/[^a-zA-Z0-9_]/g,'')+'}';
 }
 function visit(n){
  if(ts.isImportDeclaration(n)&&ts.isStringLiteral(n.moduleSpecifier)){
   const p=n.moduleSpecifier.text;const target=p.startsWith('@/')?path.join(root,p.slice(2)):p.startsWith('.')?path.resolve(path.dirname(file),p):null;
   if(target){const found=[target,target+'.ts',target+'.tsx',target+'/index.ts',target+'/index.tsx'].find(f=>fs.existsSync(f)&&fs.statSync(f).isFile());if(found)imports.push(found);}
  }
  if(ts.isCallExpression(n)&&ts.isPropertyAccessExpression(n.expression)&&['get','getBlob'].includes(n.expression.name.text)){
   const uri=value(n.arguments[0]).split('?')[0].replace(/\{(qs|query)\}$/,'');
   if(uri.startsWith('/v1/')){
    const matches=routes.filter(r=>r.methods.includes('GET')&&new RegExp('^'+r.uri.replace(/\{[^}]+\}/g,'[^/]+')+'$').test(uri));
    endpoints.push({uri,registered:matches.length>0,permissions:[...new Set(matches.flatMap(r=>r.middleware.filter(m=>m.startsWith('identity.require-permission:')).map(m=>m.split(':')[1])))]});
   }
  }
  ts.forEachChild(n,visit);
 }visit(source);graph.set(file,{imports,endpoints});
}
const pages=files.filter(f=>f.endsWith(path.sep+'page.tsx')).map(file=>{
 const visited=new Set(), endpoints=new Map();
 function trace(f){if(visited.has(f))return;visited.add(f);const g=graph.get(f);if(!g)return;for(const e of g.endpoints)endpoints.set(e.uri,e);for(const i of g.imports)trace(i);}
 trace(file);
 return {page:'/'+path.relative(root+'/app',path.dirname(file)).split(path.sep).filter(s=>!s.startsWith('(')).join('/'),source:path.relative(root,file).split(path.sep).join('/'),dependencies:[...endpoints.values()]};
});
fs.writeFileSync(__dirname+'/current-page-map.json',JSON.stringify(pages,null,2));
const unmatched=[...new Map([...graph.values()].flatMap(g=>g.endpoints).filter(e=>!e.registered).map(e=>[e.uri,e])).values()];
console.log(JSON.stringify({pages:pages.length,unmatched},null,2));

// Compare captured artifacts without installing an image-editing dependency.
const {PNG}=require('playwright-core/lib/utilsBundle');
const fs=require('node:fs');const path=require('node:path');
const dir=path.resolve(__dirname,'../../../planning/evidence/m3a');const results=[];
for(const width of [1440,1024,390]) for(const [portal,reference] of [['partner','partner-my-leads'],['brand','brand-partner-hub']]) {
 const a=PNG.sync.read(fs.readFileSync(`${dir}/reference-${reference}-${width}.png`));const b=PNG.sync.read(fs.readFileSync(`${dir}/vue-${portal}-${width}.png`));
 if(a.width!==b.width || a.height!==b.height)throw Error('Image dimensions differ');
 const diff=new PNG({width:a.width,height:a.height});const side=new PNG({width:a.width*2,height:a.height});let pixels=0;
 PNG.bitblt(a,side,0,0,a.width,a.height,0,0);PNG.bitblt(b,side,0,0,b.width,b.height,a.width,0);
 for(let i=0;i<a.data.length;i+=4){const changed=[0,1,2,3].some(c=>a.data[i+c]!==b.data[i+c]);if(changed)pixels++;diff.data[i]=changed?255:255;diff.data[i+1]=changed?0:255;diff.data[i+2]=changed?150:255;diff.data[i+3]=255;}
 fs.writeFileSync(`${dir}/diff-${portal}-${width}.png`,PNG.sync.write(diff));fs.writeFileSync(`${dir}/side-by-side-${portal}-${width}.png`,PNG.sync.write(side));
 results.push({portal,viewportWidth:width,imageWidth:a.width,height:a.height,differentPixels:pixels,totalPixels:a.width*a.height,differentFraction:pixels/(a.width*a.height)});
}
fs.writeFileSync(`${dir}/image-differences.json`,JSON.stringify(results,null,2));console.log(JSON.stringify(results,null,2));

import fs from 'node:fs';
import {fileURLToPath} from 'node:url';
import {PACKAGES} from '../recommendation.mjs';

const root = new URL('../', import.meta.url);
const escape = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = value => value.toLocaleString('ru-RU') + ' ₽';
const products = JSON.parse(fs.readFileSync(new URL('catalogue.json', root), 'utf8'));
const packages = Object.values(PACKAGES).map(p => '<article class="package"><h2>'+escape(p.name)+'</h2><div class="price">'+money(p.price)+'</div><ul>'+p.items.map(i=>'<li>'+escape(i)+'</li>').join('')+'</ul><p class="small">'+escape(p.days)+' после получения материалов · 1 круг правок</p><a class="primary" href="services">Собрать запрос ↗</a></article>').join('');
const catalogue = products.map(p => '<article class="catalogue-item"><header class="catalogue-item-heading"><h2>'+escape(p[4]?.title||p[1])+'</h2><p class="catalogue-item-subtitle">'+escape(p[4]?.subtitle||'')+'</p></header><div class="price">'+(p[2]===null?'По запросу':money(p[2]))+'</div><p>'+escape(p[3])+'</p><button class="primary" data-add="'+escape(p[0])+'" disabled>Добавить в запрос +</button></article>').join('');
const embedded = '<script type="application/json" id="catalogue-data">'+JSON.stringify(products).replace(/</g,'\\u003c')+'</script>';
for (const [name, pattern, replacement] of [
 ['maps.html', /<div id="packages" class="package-grid">[\s\S]*?<\/div>(?:<script type="module">import \{PACKAGES\}[\s\S]*?<\/script>)?(?=<a class="primary" href=".\/#project")/, '<div id="packages" class="package-grid">'+packages+'</div>'],
 ['services.html', /<div id="catalogue" class="catalogue-grid">[\s\S]*?<\/div>(?:<script type="application\/json" id="catalogue-data">[\s\S]*?<\/script>)?(?:<noscript class="catalogue-help">[\s\S]*?<\/noscript>)?(?=<section class="cart-panel">)/, '<div id="catalogue" class="catalogue-grid">'+catalogue+'</div>'+embedded+'<noscript class="catalogue-help"><p>Для выбора услуг включите JavaScript или <a href="https://t.me/Elimorastudio">напишите в Telegram</a>. Цены и состав услуг указаны выше.</p></noscript>']
]) {
 const path = new URL(name, root);
 const before = fs.readFileSync(path, 'utf8');
 if (!pattern.test(before)) throw Error('Price container missing in '+name);
 const after = before.replace(pattern, () => replacement);
 if (process.argv.includes('--check')) {
  if (before !== after) throw Error('Run node scripts/render-public-prices.mjs to update '+name);
 } else fs.writeFileSync(path, after);
 console.log('Prices ready:', fileURLToPath(path));
}


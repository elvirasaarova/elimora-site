/* Public-site analytics. Cabinet pages and non-production previews never load the tag. */
(function () {
  'use strict';
  var routes = new Set(['/', '/index.html', '/about', '/cafe', '/contacts', '/creative-formats', '/jasmine', '/kids', '/logos', '/maps-before-ads', '/maps', '/profiles', '/portfolio', '/privacy', '/profile-content', '/services', '/terms', '/useful', '/visual', '/website-before-ads', '/websites']);
  var path = location.pathname.replace(/\/$/, '') || '/';
  var publicPath = path === '/index.html' ? path : path.replace(/\.html$/, '');
  if (!['elimora.ru', 'www.elimora.ru'].includes(location.hostname) || !routes.has(publicPath) || window.elimoraMetrikaLoaded) return;
  window.elimoraMetrikaLoaded = true;
  var page = new URL(location.href);
  var marketing = new URLSearchParams();
  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid', 'gclid'].forEach(function (key) {
    if (page.searchParams.has(key)) marketing.set(key, page.searchParams.get(key));
  });
  page.search = marketing.toString();
  page.hash = '';
  var referrer = '';
  try {
    if (document.referrer) {
      var previous = new URL(document.referrer);
      referrer = previous.origin + previous.pathname;
    }
  } catch (_) {}
  (function(m,e,t,r,i,k,a){
    m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
    m[i].l=1*new Date();
    for(var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r)return}
    k=e.createElement(t);a=e.getElementsByTagName(t)[0];k.async=1;k.src=r;a.parentNode.insertBefore(k,a);
  })(window,document,'script','https://mc.yandex.ru/metrika/tag.js?id=113823074','ym');
  window.ym(113823074, 'init', {
    ssr: true, webvisor: false, clickmap: false, ecommerce: false,
    referrer: referrer, url: page.href, accurateTrackBounce: true,
    trackLinks: true, trackHash: false
  });
})();

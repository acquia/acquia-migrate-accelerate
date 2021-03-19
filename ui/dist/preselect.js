!function(e){function t(t){for(var a,l,c=t[0],o=t[1],u=t[2],d=0,p=[];d<c.length;d++)l=c[d],Object.prototype.hasOwnProperty.call(r,l)&&r[l]&&p.push(r[l][0]),r[l]=0;for(a in o)Object.prototype.hasOwnProperty.call(o,a)&&(e[a]=o[a]);for(s&&s(t);p.length;)p.shift()();return i.push.apply(i,u||[]),n()}function n(){for(var e,t=0;t<i.length;t++){for(var n=i[t],a=!0,c=1;c<n.length;c++){var o=n[c];0!==r[o]&&(a=!1)}a&&(i.splice(t--,1),e=l(l.s=n[0]))}return e}var a={},r={4:0},i=[];function l(t){if(a[t])return a[t].exports;var n=a[t]={i:t,l:!1,exports:{}};return e[t].call(n.exports,n,n.exports,l),n.l=!0,n.exports}l.e=function(e){var t=[],n=r[e];if(0!==n)if(n)t.push(n[2]);else{var a=new Promise((function(t,a){n=r[e]=[t,a]}));t.push(n[2]=a);var i,c=document.createElement("script");c.charset="utf-8",c.timeout=120,l.nc&&c.setAttribute("nonce",l.nc),c.src=function(e){return l.p+""+({5:"tracker"}[e]||e)+".bundle.js"}(e);var o=new Error;i=function(t){c.onerror=c.onload=null,clearTimeout(u);var n=r[e];if(0!==n){if(n){var a=t&&("load"===t.type?"missing":t.type),i=t&&t.target&&t.target.src;o.message="Loading chunk "+e+" failed.\n("+a+": "+i+")",o.name="ChunkLoadError",o.type=a,o.request=i,n[1](o)}r[e]=void 0}};var u=setTimeout((function(){i({type:"timeout",target:c})}),12e4);c.onerror=c.onload=i,document.head.appendChild(c)}return Promise.all(t)},l.m=e,l.c=a,l.d=function(e,t,n){l.o(e,t)||Object.defineProperty(e,t,{enumerable:!0,get:n})},l.r=function(e){"undefined"!=typeof Symbol&&Symbol.toStringTag&&Object.defineProperty(e,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(e,"__esModule",{value:!0})},l.t=function(e,t){if(1&t&&(e=l(e)),8&t)return e;if(4&t&&"object"==typeof e&&e&&e.__esModule)return e;var n=Object.create(null);if(l.r(n),Object.defineProperty(n,"default",{enumerable:!0,value:e}),2&t&&"string"!=typeof e)for(var a in e)l.d(n,a,function(t){return e[t]}.bind(null,a));return n},l.n=function(e){var t=e&&e.__esModule?function(){return e.default}:function(){return e};return l.d(t,"a",t),t},l.o=function(e,t){return Object.prototype.hasOwnProperty.call(e,t)},l.p="",l.oe=function(e){throw console.error(e),e};var c=window.webpackJsonp=window.webpackJsonp||[],o=c.push.bind(c);c.push=t,c=c.slice();for(var u=0;u<c.length;u++)t(c[u]);var s=o;i.push([247,0]),n()}({247:function(e,t,n){"use strict";n.r(t);var a=n(0),r=n.n(a),i=n(24),l=n(1),c=n.n(l),o=n(20),u=n.n(o),s=n(8),d=n.n(s),p=n(2),m=n.n(p),f=n(57),g=n(3),b=function(e,t){switch(t.type){case"selectSingle":return e.includes(t.id)?e.filter((function(e){return e!==t.id})):[].concat(d()(e),[t.id]);case"selectAll":return e.length===t.list.length?[]:t.list;default:return e}},h=function(e){var t=e.filter((function(e){var t=e.links;return Object(g.y)(t)})).map((function(e){return e.id})),n=Object(a.useReducer)(b,t),r=m()(n,2),i=r[0],l=r[1];return{checked:i.length===t.length,selections:i,selectAll:function(){l({type:"selectAll",list:t})},selectSingle:function(e){l({type:"selectSingle",id:e})}}},E=n(16),y=n(87),v=n(89),O=function(e){var t=e.id,n=e.attributes,a=e.relationships,r=e.links,i=n.label,l=n.totalCount,c=a.dependencies,o=a.consistsOf;return{id:t,label:i,totalCount:l,dependencies:c.data.map((function(e){return{id:e.id}})),consistsOf:o.data.map((function(e){return{id:e.id}})),links:r}},j=r.a.createContext({}),k=function(e){var t=e.source,n=e.children,i=Object(a.useState)([]),l=m()(i,2),c=l[0],o=l[1],u=Object(a.useState)({}),s=m()(u,2),d=s[0],p=s[1],f=Object(a.useContext)(E.c).throwError,b=Object(y.a)(d),h=b.setLink,k=b.bulkUpdateMigrations,w=Object(v.a)({href:t,handleError:f}),S=m()(w,2),_=S[0],C=_.isLoading,N=_.document,M=S[1];return Object(a.useEffect)((function(){N&&(p(N.links),o(N.data.map(O)))}),[N]),Object(a.useEffect)((function(){Object.values(d).length&&Object(g.x)(d)&&h(Object(g.m)(d)[0])}),[d]),r.a.createElement(j.Provider,{value:{links:d,migrations:c,isLoading:C,bulkUpdateMigrations:k,refreshResource:M}},n)};k.propTypes={source:c.a.string.isRequired,children:c.a.oneOfType([c.a.arrayOf(c.a.node),c.a.node]).isRequired};var w=function(){var e=Object(o.useTracking)(),t=Object(a.useState)(!1),n=m()(t,2),i=n[0],l=n[1],c=Object(a.useState)(new Set),u=m()(c,2),s=u[0],p=u[1],b=Object(a.useState)(new Set),E=m()(b,2),y=E[0],v=E[1],O=Object(a.useContext)(j),k=O.migrations,w=O.bulkUpdateMigrations,S=O.refreshResource,_=h(k),C=_.checked,N=_.selections,M=_.selectAll,P=_.selectSingle,L=k.reduce((function(e,t){return e[+N.includes(t.id)].push(t),e}),[[],[]]),x=m()(L,2),D=x[0],R=x[1],q=[{label:"Selected",count:N.length,modifier:"primary",description:r.a.createElement("span",null,r.a.createElement("strong",null,"Selected")," refers to migrations that you plan to run.")},{label:"Required",count:d()(s).filter((function(e){return!N.includes(e)})).length,modifier:"secondary",description:r.a.createElement("span",null,r.a.createElement("strong",null,"Required")," are dependencies of selected migrations.")},{label:"Skipped",count:D.length,modifier:"warning",description:r.a.createElement("span",{className:"migration__import-status-desc migration__warning"},"Migrations with ",r.a.createElement("strong",null,"0")," items are ",r.a.createElement("strong",null,"skipped")," ","by default, but you can choose to skip any others that you do not wish to import now.")}];return Object(a.useEffect)((function(){p(N.reduce((function(e,t){return Object(g.a)(Object(g.i)(t,k),k).forEach((function(t){return e.add(t)})),e}),new Set))}),[N]),Object(a.useEffect)((function(){v(new Set([].concat(d()(s),d()(N))))}),[N,s]),r.a.createElement("form",{className:"preselect__list"},r.a.createElement("div",null,r.a.createElement("table",null,r.a.createElement("thead",null,r.a.createElement("tr",null,r.a.createElement("th",null,r.a.createElement(f.a,{id:"allMigrations",options:{checked:C,disabled:!1},toggle:M})),r.a.createElement("th",null,"Name"),r.a.createElement("th",{style:{"min-width":"6rem"}},"Number of Items"),r.a.createElement("th",null,"Dependencies"))),r.a.createElement("tbody",null,k.map((function(e){return r.a.createElement("tr",{key:e.id},r.a.createElement("td",null,r.a.createElement(f.a,{id:e.id,options:{checked:N.includes(e.id)||s.has(e.id),disabled:Object(g.z)(e.links)||s.has(e.id)},toggle:function(){P(e.id)}})),r.a.createElement("td",null,e.label),r.a.createElement("td",{className:"col--align"},0!==e.totalCount&&N.includes(e.id)?r.a.createElement("span",null,e.totalCount):r.a.createElement("span",{className:"migration__import-status migration__warning",title:"".concat(0===e.totalCount?"Migrations with 0 items are skipped by default":"".concat(e.totalCount," migrations will be skipped."))},e.totalCount)),r.a.createElement("td",null,Object(g.b)(e,k).map((function(e){return e.label})).join(", ")))}))))),r.a.createElement("div",{className:"preselect__start"},r.a.createElement("button",{type:"submit",disabled:i||0===y.size,className:"button button--primary",onClick:function(t){t.preventDefault(),l(!0);var n=Date.now();e.trackEvent({type:"Preselect started",preconfigSelectedQuantity:R.length,preconfigSkippedQuantity:D.length,preconfigSelectedRows:Object(g.O)(R,"totalCount"),preconfigSkippedRows:Object(g.O)(D,"totalCount"),preconfigSelectedList:R.map((function(e){return e.id})),preconfigSkippedList:D.map((function(e){return e.id})),preconfigSelectedUnderlyingList:Object(g.D)(R),preconfigSkippedUnderlyingList:Object(g.D)(D)}),w(k.map((function(e){var t=e.id;return{type:"migration",id:t,attributes:{skipped:!y.has(t)}}}))).then((function(t){e.trackEvent({type:"Preselect completed",preconfigDuration:Date.now()-n}),S()}))}},"Start Migration"),y.size>0?r.a.createElement("strong",null,"(",y.size,") Selected items"):r.a.createElement("span",null,"No migrations selected")),r.a.createElement("div",{className:"panel panel--info"},r.a.createElement("div",{className:"panel__content"},r.a.createElement("div",{className:"preselection_summary"},q.filter((function(e){return e.count>0})).map((function(e){return r.a.createElement("div",{key:"".concat(e.label,"--count")},r.a.createElement("span",{className:"preselection_summary__count"},e.count),r.a.createElement("span",{className:"preselection_summary__label"},e.label))}))),q.filter((function(e){return e.count>0})).map((function(e){return r.a.createElement("p",{key:"".concat(e.label,"--desc")},e.description)})))))},S=n(44),_=function(){var e=Object(a.useContext)(j),t=e.migrations,n=e.links,i=e.isLoading;return!!t.length?Object(g.x)(n)?r.a.createElement("div",null,r.a.createElement("div",null,r.a.createElement("p",null,"Choose which parts of your source site you want to migrate into your new Drupal 9 site."),r.a.createElement("p",null,r.a.createElement("em",null,"Don't worry"),", you can still choose to bring over anything later that you skip now.")),r.a.createElement(w,null)):r.a.createElement("div",null,r.a.createElement("p",null,"Preselections made successfully."),Object(g.w)(n)?r.a.createElement("p",null,"Visit the dashboard to begin an initial import of supporting configuration. When that is complete, you may begin importing content."):r.a.createElement("p",null,"The initial import of your selected migrations is complete, you no longer need to visit this page."),r.a.createElement("a",{className:"button button--primary",href:"/acquia-migrate-accelerate/migrations"},"View Migrations Dashboard")):r.a.createElement(S.a,{pending:i,empty:"No migrations available."})},C=n(23),N=n(41),M=n(45),P=function(e){var t=e.basepath,n=e.source;return r.a.createElement(k,{basepath:t,source:n},r.a.createElement("div",{className:"migrate-ui"},r.a.createElement(N.a,null,r.a.createElement(M.a,{title:"Select data to migrate"})),r.a.createElement("div",null,r.a.createElement(_,null))))},L=new C.a,x=u()({},{dispatch:function(e){return L.logEvent(e)}})(P);P.propTypes={basepath:c.a.string.isRequired,source:c.a.string.isRequired};var D=n(58),R=n(70),q=n(53),A=Object(R.a)(D.a);document.addEventListener("DOMContentLoaded",(function(){var e=document.querySelector("#decoupled-page-root"),t=e.getAttribute("data-module-path");n.p="/".concat(t,"/ui/dist/");var a=e.getAttribute("data-basepath"),l=e.getAttribute("data-source");(new C.a).init(e.getAttribute("data-tracking-api-key"),Object(q.a)(e),void 0),e&&Object(i.render)(r.a.createElement(E.b,null,r.a.createElement(E.d,null,r.a.createElement(x,{basepath:a,source:l})),r.a.createElement(E.a,null,r.a.createElement(A,null))),e)}))}});
import{P as r}from"./layout-sidebar.vue.66485d1b.js";import{u as o}from"./use-events.a9188a22.js";import i from"./index.bfa9e54c.js";import{d as m}from"./entry.769d5560.js";import"./page-header.68d25fa4.js";import"./use-http-dump.c688feed.js";import"./use-inspector.da727da8.js";import"./code-snippet.d9f3d8a2.js";import"./use-profiler.a17647dc.js";import"./use-formats.c79ce0bd.js";import"./table-base.7c3f5cae.js";import"./dumper.11af9f7d.js";import"./sentry-exception.f11b92a7.js";import"./use-smtp.40f741b0.js";const v=m({extends:i,setup(){var e;const{events:t}=o();return(e=t==null?void 0:t.items)!=null&&e.value.length||t.getAll(),{events:t.items,title:"Sentry",type:r.SENTRY}},head(){return{title:`Sentry [${this.events.length}] | Buggregator`}}});export{v as default};

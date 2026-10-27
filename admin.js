// Painel admin: resumo, conteúdo das seções, menu e configurações.
// Só é carregado quando o admin está logado. Usa as funções da página (api, criar, msg, $, menuCompleto, carregarApp, recarregarApp).
(function () {
  "use strict";

  (function () {
    var l = document.createElement("link");
    l.rel = "stylesheet"; l.href = "admin.css?v=20261027c";
    document.head.appendChild(l);
  })();

  function el(tag, cls, txt) { return criar(tag, cls, txt); }
  function btn(txt, cls, fn) {
    var b = el("button", cls || "abtn", txt);
    b.type = "button";
    if (fn) b.addEventListener("click", fn);
    return b;
  }
  function vivo(corpo, t) { return corpo.dataset.t === t; }
  function erroTxt(r, padrao) {
    var e = r && r.data && r.data.erro;
    var mapa = {
      url_invalida: "Os links precisam começar com https://",
      video_invalido: "Link ou código do vídeo do YouTube inválido.",
      titulo_obrigatorio: "Informe o título.",
      nome_obrigatorio: "Informe o nome de cada seção.",
      secao_invalida: "Seção inválida.",
      menu_invalido: "Menu inválido.",
      campo_longo: "Algum texto passou do limite de caracteres.",
      nao_autorizado: "Sessão expirada. Entre de novo pelo link do e-mail."
    };
    return mapa[e] || padrao;
  }
  function fmtData(v) {
    try { return new Date(v).toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" }); } catch (e) { return ""; }
  }
  // Refresca o que os afiliados veem, sem recarregar a página
  function atualizarApp() { try { return carregarApp(); } catch (e) { return null; } }

  // ----- janela de formulário genérica -----
  function formulario(cfg) {
    var dlg = el("dialog", "dlg-adm");
    dlg.appendChild(el("h3", "", cfg.titulo));
    var form = el("form");
    var campos = {}, rotulos = {}, dicas = {};
    cfg.campos.forEach(function (c) {
      var id = "f-" + c.id;
      if (c.tipo === "checkbox") {
        var lb = el("label", "chk");
        var cb = el("input"); cb.type = "checkbox"; cb.id = id; cb.checked = !!c.valor;
        lb.appendChild(cb); lb.appendChild(document.createTextNode(c.label));
        form.appendChild(lb); campos[c.id] = cb; return;
      }
      var l = el("label", "", c.label); l.htmlFor = id;
      rotulos[c.id] = l;
      form.appendChild(l);
      var f;
      if (c.tipo === "textarea") { f = el("textarea"); f.rows = c.linhas || 4; }
      else if (c.tipo === "select") {
        f = el("select");
        c.opcoes.forEach(function (o) { var op = el("option", "", o[1]); op.value = o[0]; f.appendChild(op); });
      } else { f = el("input"); f.type = c.tipo || "text"; }
      f.id = id;
      if (c.max) f.maxLength = c.max;
      if (c.placeholder) f.placeholder = c.placeholder;
      if (c.obrig) f.required = true;
      f.value = c.valor != null ? c.valor : "";
      form.appendChild(f); campos[c.id] = f;
      if (c.dica != null) { var dk = el("small", "dica", c.dica); dk.hidden = !c.dica; form.appendChild(dk); dicas[c.id] = dk; }
    });
    var linha = el("div", "row");
    var cancelar = el("button", "btn ghost", "Cancelar"); cancelar.type = "button";
    var ok = el("button", "btn", "Salvar"); ok.type = "submit";
    linha.appendChild(cancelar); linha.appendChild(ok);
    form.appendChild(linha);
    var aviso = el("div", "msg"); form.appendChild(aviso);
    dlg.appendChild(form);
    document.body.appendChild(dlg);
    function fechar() { dlg.close(); dlg.remove(); }
    cancelar.addEventListener("click", fechar);
    dlg.addEventListener("cancel", function () { setTimeout(function () { dlg.remove(); }, 0); });
    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      var v = {};
      Object.keys(campos).forEach(function (k) { v[k] = campos[k].type === "checkbox" ? campos[k].checked : campos[k].value.trim(); });
      ok.disabled = true;
      var r = await cfg.salvar(v);
      ok.disabled = false;
      if (r && r.ok) { fechar(); if (cfg.depois) cfg.depois(); }
      else msg(aviso, (r && r.erro) || "Não foi possível salvar.", "err");
    });
    if (cfg.aoMontar) cfg.aoMontar({ campos: campos, rotulos: rotulos, dicas: dicas, titulo: dlg.querySelector("h3") });
    dlg.showModal();
  }

  // ===================================================================
  // RESUMO (números)
  // ===================================================================
  async function resumo(corpo) {
    var t = corpo.dataset.t;
    corpo.textContent = "";
    corpo.appendChild(el("div", "vazio", "Carregando..."));
    var r = await api("stats");
    if (!vivo(corpo, t)) return;
    corpo.textContent = "";
    if (!r.ok) { corpo.appendChild(el("div", "vazio", "Não foi possível carregar os números.")); return; }
    var d = r.data;

    var barra = el("div", "adm-bar");
    barra.appendChild(btn("⬇ Exportar afiliados (CSV)", "abtn", exportar));
    barra.appendChild(btn("↻ Atualizar", "abtn ghost", function () { resumo(corpo); }));
    var aviso = el("div", "msg");
    corpo.appendChild(barra); corpo.appendChild(aviso);

    var tiles = el("div", "ad-tiles");
    [
      ["Afiliados", d.total, ""],
      ["Aprovados", d.status.aprovado, "ok"],
      ["Pendentes", d.status.pendente, "pend"],
      ["Bloqueados", d.status.bloqueado, "bloq"],
      ["Novos hoje", d.novos_hoje, ""],
      ["Ativos nos últimos 7 dias", d.ativos_7d, ""],
      ["Aprovados por pagamento (30 dias)", d.aprovados_por_pagamento_30d, "ok"],
      ["Cursos / conteúdos", d.cursos + " / " + d.conteudos, ""]
    ].forEach(function (x) {
      var c = el("div", "ad-tile " + x[2]);
      c.appendChild(el("div", "n", String(x[1])));
      c.appendChild(el("div", "l", x[0]));
      tiles.appendChild(c);
    });
    corpo.appendChild(tiles);

    var graf = el("div", "ad-graf");
    graf.appendChild(el("h3", "", "Novos cadastros nos últimos 30 dias"));
    var max = Math.max.apply(null, d.por_dia.map(function (x) { return x.novos; }).concat([1]));
    var barras = el("div", "ad-barras");
    d.por_dia.forEach(function (x) {
      var b = el("div", "ad-bar-col");
      var i = el("div", "ad-bar-i");
      i.style.height = Math.max(x.novos ? 6 : 2, Math.round((x.novos / max) * 100)) + "%";
      if (!x.novos) i.classList.add("zero");
      b.title = x.dia.split("-").reverse().join("/") + ": " + x.novos + (x.novos === 1 ? " novo" : " novos");
      b.appendChild(i);
      barras.appendChild(b);
    });
    graf.appendChild(barras);
    var eixo = el("div", "ad-eixo");
    eixo.appendChild(el("span", "", d.por_dia[0].dia.split("-").reverse().slice(0, 2).join("/")));
    eixo.appendChild(el("span", "", "hoje"));
    graf.appendChild(eixo);
    corpo.appendChild(graf);

    async function exportar() {
      var a = await api("afiliados_listar");
      if (!a.ok) { msg(aviso, "Não foi possível exportar.", "err"); return; }
      function cel(v) {
        v = v == null ? "" : String(v);
        if (/^[=+\-@\t\r]/.test(v)) v = "'" + v; // evita fórmula em planilha
        return '"' + v.replace(/"/g, '""') + '"';
      }
      var linhas = [["E-mail", "Nome", "WhatsApp", "Status", "Observação", "Cadastro", "Último acesso"].map(cel).join(";")];
      a.data.afiliados.forEach(function (x) {
        linhas.push([x.email, x.nome, x.whatsapp, x.status, x.observacao, fmtData(x.created_at), fmtData(x.last_login_at)].map(cel).join(";"));
      });
      var blob = new Blob(["﻿" + linhas.join("\r\n")], { type: "text/csv;charset=utf-8" });
      var link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.download = "afiliados-amazole-" + new Date().toISOString().slice(0, 10) + ".csv";
      document.body.appendChild(link); link.click(); link.remove();
      setTimeout(function () { URL.revokeObjectURL(link.href); }, 2000);
      msg(aviso, a.data.afiliados.length + " afiliados exportados.", "ok");
    }
  }

  // ===================================================================
  // CONTEÚDO DAS SEÇÕES
  // ===================================================================
  var cont = { itens: [], secao: "", corpo: null, t: null, area: null, aviso: null };

  async function conteudo(corpo) {
    cont.corpo = corpo; cont.t = corpo.dataset.t;
    corpo.textContent = "";
    var secs = menuCompleto().filter(function (s) { return s.id !== "cursos"; });
    if (!cont.secao || !secs.some(function (s) { return s.id === cont.secao; })) {
      cont.secao = secs.some(function (s) { return s.id === "criativos"; }) ? "criativos" : (secs.length ? secs[0].id : "");
    }

    var barra = el("div", "adm-bar");
    var sel = el("select");
    secs.forEach(function (s) {
      var o = el("option", "", s.icone + " " + s.nome + (s.visivel === false ? " (oculta)" : "")); o.value = s.id; sel.appendChild(o);
    });
    sel.value = cont.secao;
    sel.addEventListener("change", function () { cont.secao = sel.value; desenhar(); });
    barra.appendChild(sel);
    barra.appendChild(btn("+ Novo item", "abtn", function () { form(null); }));
    barra.appendChild(el("span", "adm-count", "Os cursos ficam em “Gerenciar Cursos”."));
    cont.aviso = el("div", "msg");
    cont.area = el("div", "ad-lista");
    corpo.appendChild(barra); corpo.appendChild(cont.aviso); corpo.appendChild(cont.area);
    await recarregar();
  }

  async function recarregar() {
    var t = cont.t;
    var r = await api("conteudo_admin");
    if (cont.corpo.dataset.t !== t) return;
    if (!r.ok) { msg(cont.aviso, "Não foi possível carregar o conteúdo.", "err"); return; }
    cont.itens = r.data.itens || [];
    desenhar();
  }


  // ----- foto do produto: enviar do aparelho ou escolher uma das fotos que já estão no site -----
  var FOTOS_SITE = [
    ["formula-avancada", "Fórmula Avançada"], ["serum-olhos", "Sérum para os Olhos"], ["pink-amazonico", "Pink Amazônico"],
    ["escova-japonesinha", "Escova Japonesinha"], ["esfoliante-clareador", "Esfoliante Clareador"],
    ["sabonete-clareador", "Sabonete Clareador"], ["mxd20", "MXD20 + Barbudom"]
  ];

  // Reduz a foto no próprio aparelho (máx. 1000 px) antes de enviar: rápido e leve
  function reduzir(arquivo, maximo) {
    return new Promise(function (ok, erro) {
      var url = URL.createObjectURL(arquivo), img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        var k = Math.min(1, (maximo || 1000) / Math.max(img.width, img.height));
        var c = document.createElement("canvas");
        c.width = Math.max(1, Math.round(img.width * k)); c.height = Math.max(1, Math.round(img.height * k));
        var g = c.getContext("2d");
        g.fillStyle = "#fff"; g.fillRect(0, 0, c.width, c.height);
        g.drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(function (b) { b ? ok(b) : erro(new Error("falha")); }, "image/jpeg", 0.86);
      };
      img.onerror = function () { URL.revokeObjectURL(url); erro(new Error("imagem")); };
      img.src = url;
    });
  }

  async function enviarFoto(blob) {
    var fd = new FormData();
    fd.append("arquivo", blob, "foto.jpg");
    try {
      var r = await fetch("/api/?a=imagem_upload", { method: "POST", credentials: "same-origin", headers: { "X-Requested-With": "fetch" }, body: fd });
      var j = {}; try { j = await r.json(); } catch (e) {}
      return { ok: r.ok, data: j };
    } catch (e) { return { ok: false, data: {} }; }
  }

  function seletorFoto(campo, opc) {
    opc = opc || {};
    var caixa = el("div", "ft-box" + (opc.capa ? " ft-capa" : ""));
    var previa = el("div", "ft-previa");
    var linha = el("div", "ft-linha");
    var arq = el("input"); arq.type = "file"; arq.accept = "image/jpeg,image/png,image/webp"; arq.hidden = true;
    var enviar = btn(opc.capa ? "📷 Enviar imagem da capa" : "📷 Enviar foto do produto", "abtn peq", function () { arq.click(); });
    var tirar = btn(opc.capa ? "Remover capa" : "Remover foto", "abtn peq ghost", function () { campo.value = ""; atualizar(); });
    var estado = el("span", "ft-estado");
    linha.appendChild(enviar); linha.appendChild(tirar); linha.appendChild(estado); linha.appendChild(arq);
    var atalhos = el("div", "ft-atalhos");
    atalhos.appendChild(el("div", "ft-rot", "Ou use uma foto que já está no site:"));
    var fila = el("div", "ft-fila");
    FOTOS_SITE.forEach(function (f) {
      var u = location.origin + "/img/produtos/" + f[0] + ".webp";
      var b = el("button", "ft-mini"); b.type = "button"; b.title = f[1];
      var im = el("img"); im.src = u; im.alt = f[1]; im.loading = "lazy"; im.width = 52; im.height = 52;
      b.appendChild(im);
      b.addEventListener("click", function () { campo.value = u; atualizar(); });
      fila.appendChild(b);
    });
    atalhos.appendChild(fila);
    caixa.appendChild(previa); caixa.appendChild(linha); if (!opc.capa) caixa.appendChild(atalhos);

    function atualizar() {
      previa.textContent = "";
      var u = campo.value.trim();
      tirar.hidden = !u;
      if (/^https:\/\//i.test(u)) {
        var im = el("img"); im.src = u; im.alt = "Prévia da foto"; im.referrerPolicy = "no-referrer";
        im.addEventListener("error", function () { previa.textContent = ""; previa.appendChild(el("span", "ft-erro", "Não foi possível mostrar essa imagem.")); });
        previa.appendChild(im);
      } else previa.appendChild(el("span", "ft-vazio", "Sem foto"));
    }
    campo.addEventListener("input", atualizar);
    arq.addEventListener("change", async function () {
      var a = arq.files && arq.files[0];
      arq.value = "";
      if (!a) return;
      estado.textContent = "Enviando...";
      enviar.disabled = true;
      try {
        var blob = await reduzir(a, opc.capa ? 1000 : 1000);
        var r = await enviarFoto(blob);
        if (r.ok && r.data.url) { campo.value = r.data.url; estado.textContent = "Foto enviada."; }
        else estado.textContent = r.data && r.data.erro === "formato_invalido" ? "Use JPG, PNG ou WEBP." : "Não foi possível enviar a foto.";
      } catch (e) { estado.textContent = "Não foi possível ler essa imagem."; }
      enviar.disabled = false;
      atualizar();
    });
    atualizar();
    return caixa;
  }

  window.AdminFoto = function (campo, opc) { return seletorFoto(campo, opc); };

  // Em Criativos e Produtos cada item é um produto com a pasta do Google Drive
  function modo(secao) {
    if (secao === "criativos") return { item: "Produto", titulo: "Produto", link: "Link da pasta do Google Drive", ph: "https://drive.google.com/drive/folders/...", texto: "Descrição (opcional)", dica: "No Drive: botão direito na pasta → Compartilhar → “Qualquer pessoa com o link” → Leitor. Senão o afiliado não consegue abrir." };
    if (secao === "funil") return { item: "Funil", titulo: "Título do funil", link: "Link do funil", ph: "https://...", texto: "Descrição (opcional) — de qual produto é o funil", dica: "" };
    if (secao === "grupo") return { item: "Grupo", titulo: "Nome do grupo", link: "Link do grupo do WhatsApp", ph: "https://chat.whatsapp.com/...", texto: "Descrição (opcional)", dica: "Ao clicar em “Grupo Whatsapp” no menu, o afiliado vai direto para este link. Se houver mais de um, vale o primeiro da lista (ordem 1)." };
    if (secao === "produtos") return { item: "Produto", titulo: "Nome do produto", link: "Link (página do produto ou Drive)", ph: "https://...", texto: "Descrição", dica: "" };
    return { item: "Item", titulo: "Título", link: "Link do botão “Abrir” (https, opcional)", ph: "https://...", texto: "Texto", dica: "" };
  }
  function ehDrive(u) { return /^https:\/\/(drive|docs)\.google\.com\//i.test(u || ""); }

  function itensDaSecao() { return cont.itens.filter(function (i) { return i.secao === cont.secao; }); }

  async function mover(lista, i, dir) {
    var j = i + dir;
    if (j < 0 || j >= lista.length) return;
    var arr = lista.slice(); var tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
    for (var k = 0; k < arr.length; k++) {
      if (arr[k].ordem !== k + 1) {
        var x = arr[k];
        var r = await api("conteudo_salvar", { id: x.id, secao: x.secao, titulo: x.titulo, texto: x.texto || "", link: x.link || "", imagem_url: x.imagem_url || "", ordem: k + 1, publicado: x.publicado });
        if (!r.ok) { msg(cont.aviso, "Não foi possível reordenar.", "err"); break; }
      }
    }
    await atualizarApp();
    await recarregar();
  }

  function desenhar() {
    cont.area.textContent = "";
    var lista = itensDaSecao();
    if (!lista.length) { cont.area.appendChild(el("div", "vazio", "Nenhum item nesta seção. Clique em “+ Novo item”.")); return; }
    lista.forEach(function (it, i) {
      var c = el("div", "ad-curso");
      var h = el("div", "ad-head");
      var th = el("div", "ad-thumb");
      if (/^https:\/\//i.test(it.imagem_url || "")) {
        var im = el("img"); im.src = it.imagem_url; im.alt = ""; im.loading = "lazy"; im.referrerPolicy = "no-referrer";
        im.addEventListener("error", function () { im.remove(); th.classList.add("sem-capa"); });
        th.appendChild(im);
      } else th.classList.add("sem-capa");
      h.appendChild(th);
      var info = el("div", "ad-info");
      info.appendChild(el("strong", "", "#" + it.ordem + "  " + it.titulo));
      if (it.texto) info.appendChild(el("div", "ad-trecho", it.texto.length > 120 ? it.texto.slice(0, 120) + "…" : it.texto));
      var meta = el("div", "ad-meta");
      meta.appendChild(el("span", "ad-selo " + (it.publicado ? "ok" : "rasc"), it.publicado ? "Publicado" : "Rascunho"));
      if (it.link) meta.appendChild(el("span", "ad-selo dest", ehDrive(it.link) ? "Google Drive" : "Com link"));
      else meta.appendChild(el("span", "ad-selo rasc", "Sem link"));
      info.appendChild(meta);
      h.appendChild(info);
      var ac = el("div", "ad-acoes");
      var s = btn("▲", "lnk", function () { mover(lista, i, -1); }); s.disabled = i === 0;
      var d = btn("▼", "lnk", function () { mover(lista, i, 1); }); d.disabled = i === lista.length - 1;
      ac.appendChild(s); ac.appendChild(d);
      ac.appendChild(btn("Editar", "lnk", function () { form(it); }));
      ac.appendChild(btn("Remover", "lnk del", async function () {
        if (!confirm("Remover “" + it.titulo + "”? Essa ação não pode ser desfeita.")) return;
        var r = await api("conteudo_remover", { id: it.id });
        if (!r.ok) { msg(cont.aviso, "Não foi possível remover.", "err"); return; }
        msg(cont.aviso, "Item removido.", "ok");
        await atualizarApp(); recarregar();
      }));
      h.appendChild(ac);
      c.appendChild(h);
      cont.area.appendChild(c);
    });
  }

  // Vários links de afiliação (Produtos): cada um com um nome e o endereço
  function montarLinks(iniciais) {
    var caixa = el("div", "lk-box");
    caixa.appendChild(el("div", "lk-tit", "Links para se afiliar a este produto"));
    caixa.appendChild(el("small", "dica", "Adicione quantos precisar (ex.: “Hotmart”, “Kiwify”, “Página oficial”). Cada um vira um botão para o afiliado."));
    var lista = el("div", "lk-lista");
    caixa.appendChild(lista);
    function linha(rot, url) {
      var r = el("div", "lk-linha");
      var i1 = el("input"); i1.type = "text"; i1.maxLength = 80; i1.placeholder = "Nome (ex.: Hotmart)"; i1.value = rot || "";
      var i2 = el("input"); i2.type = "url"; i2.maxLength = 500; i2.placeholder = "https://..."; i2.value = url || "";
      var x = btn("✕", "lnk del", function () { r.remove(); });
      x.title = "Remover link"; x.setAttribute("aria-label", "Remover link");
      r.appendChild(i1); r.appendChild(i2); r.appendChild(x);
      lista.appendChild(r);
      return r;
    }
    (iniciais || []).forEach(function (l) { linha(l.rotulo, l.url); });
    var mais = btn("+ Adicionar link", "abtn ghost peq", function () { linha("", "").querySelector("input").focus(); });
    caixa.appendChild(mais);
    return {
      caixa: caixa,
      valor: function () {
        var out = [];
        lista.querySelectorAll(".lk-linha").forEach(function (r) {
          var ins = r.querySelectorAll("input");
          var u = ins[1].value.trim();
          if (u) out.push({ rotulo: ins[0].value.trim(), url: u });
        });
        return out;
      }
    };
  }

  function form(it) {
    var blocoLinks = null;
    var secs = menuCompleto().filter(function (s) { return s.id !== "cursos"; });
    var m0 = modo(it ? it.secao : cont.secao);
    formulario({
      titulo: it ? "Editar " + m0.item.toLowerCase() : "Novo " + m0.item.toLowerCase(),
      campos: [
        { id: "secao", label: "Seção", tipo: "select", opcoes: secs.map(function (s) { return [s.id, s.icone + " " + s.nome]; }), valor: it ? it.secao : cont.secao },
        { id: "titulo", label: m0.titulo, max: 200, obrig: true, valor: it ? it.titulo : "" },
        { id: "ordem", label: "Ordem (1 aparece primeiro)", tipo: "number", valor: it ? it.ordem : itensDaSecao().length + 1 },
        { id: "link", label: m0.link, tipo: "url", max: 500, placeholder: m0.ph, valor: it ? it.link : "", dica: m0.dica },
        { id: "texto", label: m0.texto, tipo: "textarea", linhas: 3, max: 5000, valor: it ? it.texto : "" },
        { id: "imagem_url", label: "Foto do produto (aparece no cartão do afiliado)", tipo: "url", max: 500, placeholder: "Envie a foto abaixo ou cole um link https", valor: it ? it.imagem_url : "" },
        { id: "publicado", label: "Publicado (visível para afiliados)", tipo: "checkbox", valor: it ? it.publicado : true }
      ],
      aoMontar: function (f) {
        function aplicar() {
          var m = modo(f.campos.secao.value);
          f.rotulos.titulo.textContent = m.titulo;
          f.rotulos.link.textContent = m.link;
          f.campos.link.placeholder = m.ph;
          f.rotulos.texto.textContent = m.texto;
          f.dicas.link.textContent = m.dica;
          f.dicas.link.hidden = !m.dica;
        }
        f.campos.secao.addEventListener("change", aplicar);
        aplicar();
        f.campos.imagem_url.after(seletorFoto(f.campos.imagem_url));
        blocoLinks = montarLinks(it && it.links ? it.links : []);
        f.campos.texto.after(blocoLinks.caixa);
        function mostrarLinks() { blocoLinks.caixa.hidden = f.campos.secao.value !== "produtos"; }
        f.campos.secao.addEventListener("change", mostrarLinks);
        mostrarLinks();
      },
      salvar: async function (v) {
        var d = { secao: v.secao, titulo: v.titulo, texto: v.texto, link: v.link, imagem_url: v.imagem_url, ordem: parseInt(v.ordem, 10) || 0, publicado: v.publicado };
        if (blocoLinks) d.links = v.secao === "produtos" ? blocoLinks.valor() : [];
        if (it) d.id = it.id;
        var r = await api("conteudo_salvar", d);
        if (r.ok) { cont.secao = v.secao; return { ok: true }; }
        return { ok: false, erro: erroTxt(r, "Não foi possível salvar.") };
      },
      depois: async function () {
        await atualizarApp();
        msg(cont.aviso, "Salvo.", "ok");
        conteudo(cont.corpo);
      }
    });
  }

  // ===================================================================
  // EDITAR MENU
  // ===================================================================
  function menu(corpo) {
    var t = corpo.dataset.t;
    corpo.textContent = "";
    var linhas = menuCompleto().map(function (x) {
      return { id: x.id, nome: x.nome, icone: x.icone, sub: x.sub || "", visivel: x.visivel !== false, tipo: x.posts ? "posts" : "cards", custom: !!x.custom };
    });
    var aviso = el("div", "msg");
    var barra = el("div", "adm-bar");
    barra.appendChild(btn("+ Nova seção", "abtn ghost", function () {
      linhas.push({ id: "c_" + Math.random().toString(36).slice(2, 8), nome: "Nova seção", icone: "📌", sub: "", visivel: true, tipo: "cards", custom: true });
      desenhar();
    }));
    barra.appendChild(btn("Salvar menu", "abtn", salvar));
    barra.appendChild(btn("Restaurar padrão", "abtn ghost", restaurar));
    barra.appendChild(el("span", "adm-count", "Mude nome, ícone, ordem e visibilidade das seções."));
    var area = el("div", "ad-lista");
    corpo.appendChild(barra); corpo.appendChild(aviso); corpo.appendChild(area);

    function desenhar() {
      area.textContent = "";
      linhas.forEach(function (x, i) {
        var c = el("div", "mn-row" + (x.visivel ? "" : " oculta"));
        var ic = el("input", "mn-ic"); ic.value = x.icone; ic.maxLength = 8; ic.setAttribute("aria-label", "Ícone");
        ic.addEventListener("input", function () { x.icone = ic.value; });
        var nm = el("input", "mn-nome"); nm.value = x.nome; nm.maxLength = 40; nm.setAttribute("aria-label", "Nome");
        nm.addEventListener("input", function () { x.nome = nm.value; });
        var sb = el("input", "mn-sub"); sb.value = x.sub; sb.maxLength = 150; sb.placeholder = "Descrição que aparece no topo da seção"; sb.setAttribute("aria-label", "Descrição");
        sb.addEventListener("input", function () { x.sub = sb.value; });
        c.appendChild(ic); c.appendChild(nm); c.appendChild(sb);
        var ac = el("div", "ad-acoes");
        if (x.id !== "cursos") {
          var tp = el("select"); tp.setAttribute("aria-label", "Formato");
          [["cards", "Cartões"], ["posts", "Lista"]].forEach(function (o) { var op = el("option", "", o[1]); op.value = o[0]; tp.appendChild(op); });
          tp.value = x.tipo;
          tp.addEventListener("change", function () { x.tipo = tp.value; });
          ac.appendChild(tp);
        } else ac.appendChild(el("span", "ad-selo dest", "Cursos"));
        var vis = btn(x.visivel ? "👁 Visível" : "🚫 Oculta", "lnk", function () { x.visivel = !x.visivel; desenhar(); });
        var up = btn("▲", "lnk", function () { if (i) { var tmp = linhas[i - 1]; linhas[i - 1] = x; linhas[i] = tmp; desenhar(); } }); up.disabled = i === 0;
        var dn = btn("▼", "lnk", function () { if (i < linhas.length - 1) { var tmp = linhas[i + 1]; linhas[i + 1] = x; linhas[i] = tmp; desenhar(); } }); dn.disabled = i === linhas.length - 1;
        ac.appendChild(vis); ac.appendChild(up); ac.appendChild(dn);
        if (x.custom) ac.appendChild(btn("Remover", "lnk del", function () {
          if (!confirm("Remover a seção “" + x.nome + "”? Os itens dela continuam salvos, mas deixam de aparecer.")) return;
          linhas.splice(i, 1); desenhar();
        }));
        c.appendChild(ac);
        area.appendChild(c);
      });
    }
    desenhar();

    async function salvar() {
      if (linhas.some(function (x) { return !x.nome.trim(); })) { msg(aviso, "Todas as seções precisam de um nome.", "err"); return; }
      var r = await api("menu_salvar", { menu: linhas.map(function (x) {
        return { id: x.id, nome: x.nome.trim(), icone: x.icone.trim() || "•", sub: x.sub.trim(), visivel: x.visivel, tipo: x.tipo };
      }) });
      if (!vivo(corpo, t)) return;
      if (!r.ok) { msg(aviso, erroTxt(r, "Não foi possível salvar o menu."), "err"); return; }
      await recarregarApp("admin_menu");
    }
    async function restaurar() {
      if (!confirm("Voltar o menu para o padrão? Seções criadas por você deixam de aparecer (o conteúdo delas continua salvo).")) return;
      var r = await api("menu_salvar", { menu: null });
      if (!r.ok) { msg(aviso, "Não foi possível restaurar.", "err"); return; }
      await recarregarApp("admin_menu");
    }
  }

  // ===================================================================
  // CONFIGURAÇÕES
  // ===================================================================
  async function config(corpo) {
    var t = corpo.dataset.t;
    corpo.textContent = "";
    corpo.appendChild(el("div", "vazio", "Carregando..."));
    var r = await api("config_ler");
    if (!vivo(corpo, t)) return;
    corpo.textContent = "";
    if (!r.ok) { corpo.appendChild(el("div", "vazio", "Não foi possível carregar as configurações.")); return; }
    var d = r.data;

    // ----- página inicial -----
    var s1 = el("section", "cf-box");
    s1.appendChild(el("h3", "", "Página inicial"));
    function campo(pai, rot, valor, ph, dica) {
      var l = el("label", "", rot);
      var i = el("input"); i.type = "text"; i.value = valor || ""; i.placeholder = ph || ""; i.maxLength = 500;
      l.appendChild(i);
      if (dica) l.appendChild(el("small", "", dica));
      pai.appendChild(l);
      return i;
    }
    var inCheckout = campo(s1, "Link de pagamento (checkout)", d.checkout_url, "https://...", "Todos os botões “Entrar na comunidade” levam para este link. Vazio mantém o link atual do site.");
    var inVideo = campo(s1, "Vídeo do YouTube (link ou código)", d.video_id, "https://youtu.be/...", "Aparece na seção “Veja como funciona na prática”. Vazio esconde a seção.");

    s1.appendChild(el("h4", "", "Depoimentos"));
    var dep = (d.depoimentos || []).map(function (x) { return { texto: x.texto, autor: x.autor || "" }; });
    var listaDep = el("div", "cf-deps");
    function desenharDep() {
      listaDep.textContent = "";
      dep.forEach(function (x, i) {
        var li = el("div", "cf-dep");
        var tx = el("textarea"); tx.rows = 2; tx.maxLength = 400; tx.value = x.texto; tx.placeholder = "Texto do depoimento";
        tx.addEventListener("input", function () { x.texto = tx.value; });
        var au = el("input"); au.maxLength = 80; au.value = x.autor; au.placeholder = "Nome, cidade";
        au.addEventListener("input", function () { x.autor = au.value; });
        li.appendChild(tx); li.appendChild(au);
        li.appendChild(btn("Remover", "lnk del", function () { dep.splice(i, 1); desenharDep(); }));
        listaDep.appendChild(li);
      });
      if (!dep.length) listaDep.appendChild(el("div", "ad-trecho", "Nenhum depoimento. Só use depoimentos reais de clientes."));
    }
    desenharDep();
    s1.appendChild(listaDep);
    var linhaBt = el("div", "adm-bar");
    linhaBt.appendChild(btn("+ Adicionar depoimento", "abtn ghost", function () { if (dep.length < 12) { dep.push({ texto: "", autor: "" }); desenharDep(); } }));
    var avisoSalvar = el("div", "msg");
    linhaBt.appendChild(btn("Salvar página inicial", "abtn", async function () {
      var rr = await api("config_salvar", { checkout_url: inCheckout.value.trim(), video_id: inVideo.value.trim(), depoimentos: dep });
      msg(avisoSalvar, rr.ok ? "Salvo. A página inicial já usa os novos dados." : erroTxt(rr, "Não foi possível salvar."), rr.ok ? "ok" : "err");
    }));
    s1.appendChild(linhaBt); s1.appendChild(avisoSalvar);
    corpo.appendChild(s1);

    // ----- aprovação automática -----
    var s2 = el("section", "cf-box");
    s2.appendChild(el("h3", "", "Aprovação automática pelo pagamento"));
    s2.appendChild(el("p", "ad-trecho", "Cole o endereço abaixo no campo de webhook (ou postback / notificação) da Dignopay, para o evento de pagamento aprovado. Quando a Dignopay avisar que o pagamento foi confirmado, o afiliado é aprovado sozinho. Reembolsos e cancelamentos voltam o afiliado para pendente. Quem você bloqueou continua bloqueado."));
    var url = el("input", "cf-url"); url.readOnly = true; url.value = d.webhook_url; url.setAttribute("aria-label", "Endereço do webhook");
    var avisoUrl = el("div", "msg");
    var linhaUrl = el("div", "adm-bar");
    linhaUrl.appendChild(url);
    linhaUrl.appendChild(btn("Copiar", "abtn", async function () {
      try { await navigator.clipboard.writeText(d.webhook_url); msg(avisoUrl, "Endereço copiado.", "ok"); }
      catch (e) { url.select(); msg(avisoUrl, "Selecione e copie o endereço manualmente.", ""); }
    }));
    linhaUrl.appendChild(btn("Gerar nova chave", "abtn ghost", async function () {
      if (!confirm("Gerar uma nova chave? O endereço atual para de funcionar e você precisa colar o novo na Dignopay.")) return;
      var rr = await api("webhook_regenerar", {});
      if (!rr.ok) { msg(avisoUrl, "Não foi possível gerar a chave.", "err"); return; }
      config(corpo);
    }));
    s2.appendChild(linhaUrl); s2.appendChild(avisoUrl);
    s2.appendChild(el("small", "cf-alerta", "Trate esse endereço como uma senha: quem o conhece pode aprovar afiliados."));

    s2.appendChild(el("h4", "", "Últimos avisos recebidos"));
    if (!d.eventos.length) s2.appendChild(el("div", "ad-trecho", "Ainda não chegou nenhum aviso. Depois de configurar na Dignopay, faça um pagamento de teste e ele aparece aqui."));
    else {
      var tab = el("table", "adm-tab");
      var th = el("tr");
      ["Quando", "E-mail", "Status recebido", "O que foi feito", "Dados"].forEach(function (x) { th.appendChild(el("th", "", x)); });
      var thead = el("thead"); thead.appendChild(th); tab.appendChild(thead);
      var tb = el("tbody");
      var nomes = { aprovado: "Aprovado", revogado: "Voltou para pendente", ignorado: "Ignorado", sem_email: "Sem e-mail no aviso", bloqueado_mantido: "Bloqueado (mantido)", chave_invalida: "Chegou com a chave errada" };
      d.eventos.forEach(function (e) {
        var tr = el("tr");
        tr.appendChild(el("td", "", fmtData(e.recebido_em)));
        tr.appendChild(el("td", "", e.email || "—"));
        tr.appendChild(el("td", "", e.status_pagto || "—"));
        tr.appendChild(el("td", "", nomes[e.acao] || e.acao));
        var td = el("td"); var det = el("details"); det.appendChild(el("summary", "", "ver"));
        det.appendChild(el("pre", "cf-pre", e.corpo || "")); td.appendChild(det); tr.appendChild(td);
        tb.appendChild(tr);
      });
      tab.appendChild(tb);
      var wrap = el("div", "adm-wrap"); wrap.appendChild(tab);
      s2.appendChild(wrap);
    }
    s2.appendChild(btn("↻ Atualizar avisos", "abtn ghost", function () { config(corpo); }));
    corpo.appendChild(s2);
  }

  // ===================================================================
  // ENGAJAMENTO E AVISOS POR E-MAIL
  // ===================================================================
  function uuidNovo() {
    var b = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(b);
    b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
    var h = Array.prototype.map.call(b, function (x) { return ("0" + x.toString(16)).slice(-2); }).join("");
    return h.slice(0, 8) + "-" + h.slice(8, 12) + "-" + h.slice(12, 16) + "-" + h.slice(16, 20) + "-" + h.slice(20);
  }

  async function engajamento(corpo) {
    var t = corpo.dataset.t;
    corpo.textContent = "";
    corpo.appendChild(el("div", "vazio", "Carregando..."));
    var r = await api("engajamento");
    if (!vivo(corpo, t)) return;
    corpo.textContent = "";
    if (!r.ok) { corpo.appendChild(el("div", "vazio", "Não foi possível carregar o engajamento.")); return; }
    var d = r.data;

    var barra = el("div", "adm-bar");
    barra.appendChild(btn("↻ Atualizar", "abtn ghost", function () { engajamento(corpo); }));
    corpo.appendChild(barra);

    var tiles = el("div", "ad-tiles");
    [
      ["Aprovados", d.aprovados, ""],
      ["Entraram hoje", d.hoje, "ok"],
      ["Ativos em 7 dias", d.ativos_7d, "ok"],
      ["Ativos em 30 dias", d.ativos_30d, ""],
      ["Sem entrar há " + d.dias_inativo + "+ dias", d.inativos, "pend"],
      ["Começaram a trilha", d.comecaram_trilha, ""],
      ["Concluíram a trilha", d.concluiram_trilha, "ok"]
    ].forEach(function (x) {
      var c = el("div", "ad-tile " + x[2]);
      c.appendChild(el("div", "n", String(x[1])));
      c.appendChild(el("div", "l", x[0]));
      tiles.appendChild(c);
    });
    corpo.appendChild(tiles);
    corpo.appendChild(el("p", "dica", "A contagem de acessos começa a partir de agora: quem já estava na lista aparece como \"sem entrar\" até abrir a comunidade pela primeira vez."));

    var graf = el("div", "ad-graf");
    graf.appendChild(el("h3", "", "Afiliados que entraram por dia (últimos 14 dias)"));
    var max = Math.max.apply(null, d.por_dia.map(function (x) { return x.ativos; }).concat([1]));
    var barras = el("div", "ad-barras");
    d.por_dia.forEach(function (x) {
      var b = el("div", "ad-bar-col"), i = el("div", "ad-bar-i");
      i.style.height = Math.max(x.ativos ? 6 : 2, Math.round((x.ativos / max) * 100)) + "%";
      if (!x.ativos) i.classList.add("zero");
      b.title = x.dia.split("-").reverse().join("/") + ": " + x.ativos + " ativos";
      b.appendChild(i); barras.appendChild(b);
    });
    graf.appendChild(barras);
    corpo.appendChild(graf);

    function tabela(titulo, cols, linhas, vazio) {
      var s = el("div", "ad-graf");
      s.appendChild(el("h3", "", titulo));
      if (!linhas.length) { s.appendChild(el("p", "dica", vazio)); corpo.appendChild(s); return; }
      var tab = el("table", "adm-tab"), th = el("thead"), tr = el("tr");
      cols.forEach(function (c) { tr.appendChild(el("th", "", c)); });
      th.appendChild(tr); tab.appendChild(th);
      var tb = el("tbody");
      linhas.forEach(function (l) {
        var row = el("tr");
        l.forEach(function (v) { row.appendChild(el("td", "", v)); });
        tb.appendChild(row);
      });
      tab.appendChild(tb);
      var w = el("div", "adm-wrap"); w.appendChild(tab); s.appendChild(w);
      corpo.appendChild(s);
    }
    tabela("Mais ativos (30 dias)", ["Afiliado", "E-mail", "Dias que entrou", "Último acesso"],
      d.top.map(function (x) { return [x.nome || "—", x.email, String(x.dias), x.ultimo.split("-").reverse().join("/")]; }),
      "Ainda não há acessos registrados.");
    tabela("Esfriando (aprovados sem entrar há " + d.dias_inativo + "+ dias)", ["Afiliado", "E-mail", "Último acesso"],
      d.esfriando.slice(0, 30).map(function (x) { return [x.nome || "—", x.email, fmtData(x.last_login_at)]; }),
      "Ninguém esfriou. 🎉");

    // ---- aviso por e-mail ----
    var box = el("div", "ad-graf");
    box.appendChild(el("h3", "", "Enviar aviso por e-mail"));
    box.appendChild(el("p", "dica", "Vai só para afiliados aprovados. O envio é em lotes de 20; o limite é de 400 e-mails por dia e, se parar, é só clicar de novo que continua de onde parou, sem repetir."));
    var f = el("form", "av-form");
    function campo(rot, node) { var l = el("label", "", rot); f.appendChild(l); f.appendChild(node); return node; }
    var inpAssunto = el("input"); inpAssunto.maxLength = 150; inpAssunto.placeholder = "Ex.: Produto novo na comunidade!";
    var inpMsg = el("textarea"); inpMsg.rows = 6; inpMsg.maxLength = 4000; inpMsg.placeholder = "Escreva a mensagem...";
    var inpLink = el("input"); inpLink.type = "url"; inpLink.placeholder = "https:// (opcional)";
    var selPub = el("select");
    [["aprovados", "Todos os aprovados (" + d.aprovados + ")"], ["inativos", "Só quem não entra há " + d.dias_inativo + "+ dias (" + d.inativos + ")"]].forEach(function (o) {
      var op = el("option", "", o[1]); op.value = o[0]; selPub.appendChild(op);
    });
    campo("Assunto", inpAssunto); campo("Mensagem", inpMsg); campo("Link (opcional)", inpLink); campo("Para quem", selPub);
    var lbm = el("label", "chk"), cbMural = el("input"); cbMural.type = "checkbox";
    lbm.appendChild(cbMural); lbm.appendChild(document.createTextNode(" Publicar também no Mural de novidades"));
    f.appendChild(lbm);
    var linha = el("div", "row");
    var bTeste = btn("Enviar teste para mim", "abtn ghost"); bTeste.type = "button";
    var bEnv = btn("Enviar aviso", "abtn"); bEnv.type = "submit";
    linha.appendChild(bTeste); linha.appendChild(bEnv);
    f.appendChild(linha);
    var aviso = el("div", "msg"); f.appendChild(aviso);
    box.appendChild(f); corpo.appendChild(box);

    var campanha = uuidNovo(), assinatura = "";
    function dados(pub) {
      var ass = inpAssunto.value.trim(), m = inpMsg.value.trim();
      if (!ass || !m) { msg(aviso, "Preencha o assunto e a mensagem.", "err"); return null; }
      var lk = inpLink.value.trim();
      if (lk && !/^https:\/\//i.test(lk)) { msg(aviso, "O link precisa começar com https://", "err"); return null; }
      return { campanha: campanha, assunto: ass, mensagem: m, link: lk || null, publico: pub, dias: d.dias_inativo };
    }
    bTeste.addEventListener("click", async function () {
      var x = dados("teste"); if (!x) return;
      bTeste.disabled = true; msg(aviso, "Enviando teste...");
      var r2 = await api("aviso_enviar", x);
      bTeste.disabled = false;
      msg(aviso, r2.ok ? "Teste enviado para o seu e-mail." : "Não foi possível enviar o teste. Confira o e-mail em Configurações.", r2.ok ? "ok" : "err");
    });
    f.addEventListener("submit", async function (e) {
      e.preventDefault();
      var x = dados(selPub.value); if (!x) return;
      var sig = JSON.stringify([x.assunto, x.mensagem, x.link, x.publico]);
      if (sig !== assinatura) { campanha = uuidNovo(); x.campanha = campanha; assinatura = sig; } // texto mudou = nova campanha
      var qtd = selPub.value === "inativos" ? d.inativos : d.aprovados;
      if (!window.confirm("Enviar este aviso para até " + qtd + " afiliados?")) return;
      bEnv.disabled = true; bTeste.disabled = true;
      if (cbMural.checked) {
        cbMural.checked = false;
        await api("conteudo_salvar", { secao: "novidades", titulo: x.assunto, texto: x.mensagem, link: x.link, ordem: -Math.floor(Date.now() / 1000) });
        atualizarApp();
      }
      var total = 0, falhou = false;
      while (true) {
        msg(aviso, "Enviando... " + total + " enviados até agora.");
        var r2 = await api("aviso_enviar", x);
        if (!r2.ok) { falhou = true; msg(aviso, "O envio parou depois de " + total + " e-mails (o servidor de e-mail recusou). Clique em Enviar de novo mais tarde para continuar.", "err"); break; }
        total += r2.data.enviados;
        if (r2.data.limite_diario) { msg(aviso, "Chegou ao limite diário de envios (" + total + " enviados agora). Clique em Enviar amanhã para continuar de onde parou.", "ok"); falhou = true; break; }
        if (r2.data.restantes <= 0 || r2.data.enviados === 0) break;
      }
      if (!falhou) msg(aviso, "Pronto! Aviso enviado para " + total + " afiliados.", "ok");
      bEnv.disabled = false; bTeste.disabled = false;
    });
  }

  window.Admin = { resumo: resumo, engajamento: engajamento, conteudo: conteudo, menu: menu, config: config };
})();

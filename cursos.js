// Cursos: tela estilo Netflix para o afiliado e cadastro de cursos e aulas para o admin.
// Usa as funções da página (api, criar, msg, $) e as variáveis email e ehAdmin.
(function () {
  "use strict";

  // ===== utilitários =====
  var CHAVE_VISTAS = "amazole_aulas_vistas";

  function lerVistas() {
    try { return JSON.parse(localStorage.getItem(CHAVE_VISTAS) || "{}") || {}; } catch (e) { return {}; }
  }
  function gravarVista(id, sim) {
    try {
      var o = lerVistas();
      if (sim) o[id] = 1; else delete o[id];
      localStorage.setItem(CHAVE_VISTAS, JSON.stringify(o));
    } catch (e) {}
  }
  function ehHttps(u) { return typeof u === "string" && /^https:\/\//i.test(u); }
  function el(tag, cls, txt) { return criar(tag, cls, txt); }

  function capa(c, cls) {
    var box = el("div", cls);
    if (ehHttps(c.capa_url)) {
      var im = el("img");
      im.src = c.capa_url; im.alt = ""; im.loading = "lazy"; im.referrerPolicy = "no-referrer";
      im.addEventListener("error", function () { im.remove(); box.classList.add("sem-capa"); });
      box.appendChild(im);
    } else {
      box.classList.add("sem-capa");
    }
    return box;
  }

  // Converte o link do vídeo em algo que a página sabe tocar. Só monta o endereço a partir do ID.
  // Miniatura da aula: do YouTube quando o link é de lá; senão a capa do curso
  function miniaturaAula(a, c) {
    var m = (a.video_url || "").match(/^https:\/\/(?:(?:www\.|m\.)?youtube\.com\/(?:watch\?(?:[^#]*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([\w-]{11})/i);
    if (m) return "https://i.ytimg.com/vi/" + m[1] + "/mqdefault.jpg";
    return ehHttps(c.capa_url) ? c.capa_url : "";
  }

  function incorporar(url) {
    var m;
    if ((m = url.match(/^https:\/\/(?:www\.|m\.)?youtube\.com\/watch\?(?:[^#]*&)?v=([\w-]{11})/i)) ||
        (m = url.match(/^https:\/\/youtu\.be\/([\w-]{11})/i)) ||
        (m = url.match(/^https:\/\/(?:www\.)?youtube\.com\/(?:embed|shorts|live)\/([\w-]{11})/i))) {
      return { tipo: "iframe", src: "https://www.youtube-nocookie.com/embed/" + m[1] + "?rel=0&modestbranding=1" };
    }
    if ((m = url.match(/^https:\/\/(?:www\.)?vimeo\.com\/(?:[^\s?#]*\/)?(\d{6,12})/i)) ||
        (m = url.match(/^https:\/\/player\.vimeo\.com\/video\/(\d{6,12})/i))) {
      return { tipo: "iframe", src: "https://player.vimeo.com/video/" + m[1] };
    }
    if (/^https:\/\/[^\s?#]+\.(mp4|webm|m4v)(\?[^\s#]*)?$/i.test(url)) return { tipo: "video", src: url };
    return { tipo: "link", src: url };
  }

  // ===================================================================
  // TELA DO AFILIADO (estilo Netflix)
  // ===================================================================
  var est = { cursos: [], corpo: null };

  function aviso(corpo, texto) {
    corpo.textContent = "";
    corpo.appendChild(el("div", "nf-aviso", texto));
  }

  async function afiliado(corpo) {
    var t = corpo.dataset.t;
    aviso(corpo, "Carregando cursos...");
    var r = await api("cursos_ler", { email: ehAdmin ? "" : email });
    if (corpo.dataset.t !== t) return;
    if (r.status === 403) { aviso(corpo, "Seu acesso ainda está pendente de aprovação. Assim que for aprovado, os cursos aparecem aqui."); return; }
    if (!r.ok) { aviso(corpo, "Não foi possível carregar os cursos agora. Tente de novo em instantes."); return; }
    est.cursos = (r.data && r.data.cursos) || [];
    est.corpo = corpo;
    lista();
  }

  function proximaAula(c) {
    var v = lerVistas();
    var aulas = c.aulas || [];
    for (var i = 0; i < aulas.length; i++) if (!v[aulas[i].id]) return aulas[i].id;
    return aulas.length ? aulas[0].id : null;
  }

  function progresso(c) {
    var v = lerVistas();
    var aulas = c.aulas || [];
    var n = 0;
    aulas.forEach(function (a) { if (v[a.id]) n++; });
    return { feitas: n, total: aulas.length, pct: aulas.length ? Math.round((n / aulas.length) * 100) : 0 };
  }

  function hero(c) {
    var h = el("section", "nf-hero");
    h.appendChild(capa(c, "nf-hero-img"));
    var txt = el("div", "nf-hero-txt");
    txt.appendChild(el("span", "nf-tag", "★ EM DESTAQUE"));
    txt.appendChild(el("h2", "", c.titulo));
    var na = (c.aulas || []).length, pg = progresso(c);
    var meta = el("div", "nf-chips");
    if (c.categoria) meta.appendChild(el("span", "nf-chip", c.categoria));
    meta.appendChild(el("span", "nf-chip", na + (na === 1 ? " aula" : " aulas")));
    if (pg.feitas > 0) meta.appendChild(el("span", "nf-chip ok", pg.pct + "% concluído"));
    txt.appendChild(meta);
    if (c.descricao) txt.appendChild(el("p", "", c.descricao));
    var b = el("div", "nf-btns");
    var play = el("button", "nf-play", pg.feitas > 0 && pg.feitas < pg.total ? "▶ Continuar" : "▶ Assistir");
    play.type = "button";
    play.addEventListener("click", function () { abrirCurso(c, proximaAula(c)); });
    var info = el("button", "nf-info", "Ver aulas");
    info.type = "button";
    info.addEventListener("click", function () { abrirCurso(c, null); });
    b.appendChild(play); b.appendChild(info);
    txt.appendChild(b);
    h.appendChild(txt);
    return h;
  }

  function cartao(c) {
    var b = el("button", "nf-card");
    b.type = "button";
    var p = progresso(c);
    var im = capa(c, "nf-card-img");
    var n = (c.aulas || []).length;
    im.appendChild(el("span", "nf-badge", n + (n === 1 ? " aula" : " aulas")));
    if (p.total && p.feitas === p.total) im.appendChild(el("span", "nf-badge ok", "✓ Concluído"));
    im.appendChild(el("span", "nf-playico", "▶"));
    b.appendChild(im);
    var t = el("div", "nf-card-t");
    t.appendChild(el("strong", "", c.titulo));
    t.appendChild(el("span", "", p.feitas > 0 ? p.feitas + " de " + p.total + " assistidas" : (c.categoria || "Curso")));
    b.appendChild(t);
    if (p.feitas > 0) {
      var bar = el("div", "nf-prog"), fill = el("span");
      fill.style.width = p.pct + "%";
      bar.appendChild(fill); b.appendChild(bar);
    }
    b.addEventListener("click", function () { abrirCurso(c, null); });
    return b;
  }

  function lista() {
    var corpo = est.corpo;
    corpo.textContent = "";
    var cs = est.cursos;
    if (!cs.length) { corpo.appendChild(el("div", "nf-aviso", "Ainda não há cursos publicados. Volte em breve!")); return; }
    var dest = cs.filter(function (c) { return c.destaque; })[0] || cs[0];
    corpo.appendChild(hero(dest));

    var grupos = new Map();
    cs.forEach(function (c) {
      var k = c.categoria || "Cursos";
      if (!grupos.has(k)) grupos.set(k, []);
      grupos.get(k).push(c);
    });
    function linha(nome, itens) {
      var row = el("section", "nf-row");
      var cab = el("div", "nf-row-cab");
      cab.appendChild(el("h3", "", nome));
      var tr = el("div", "nf-track");
      row.appendChild(cab);
      itens.forEach(function (c) { tr.appendChild(cartao(c)); });
      row.appendChild(tr);
      corpo.appendChild(row);
    }
    var andamento = cs.filter(function (c) { var p = progresso(c); return p.feitas > 0 && p.feitas < p.total; });
    if (andamento.length) linha("Continue assistindo", andamento);
    grupos.forEach(function (itens, nome) { linha(nome, itens); });
  }

  function player(a) {
    var box = el("div", "nf-player");
    if (!a || !ehHttps(a.video_url)) {
      box.appendChild(el("div", "nf-sem-video", "Esta aula não tem vídeo."));
      return box;
    }
    var inc = incorporar(a.video_url);
    if (inc.tipo === "iframe") {
      var f = el("iframe");
      f.src = inc.src; f.title = a.titulo;
      f.allow = "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen";
      f.allowFullscreen = true;
      f.referrerPolicy = "strict-origin-when-cross-origin";
      box.appendChild(f);
    } else if (inc.tipo === "video") {
      var v = el("video");
      v.src = inc.src; v.controls = true; v.preload = "metadata"; v.playsInline = true;
      box.appendChild(v);
    } else {
      var l = el("a", "nf-play", "▶ Abrir aula em nova aba");
      l.href = inc.src; l.target = "_blank"; l.rel = "noopener noreferrer";
      box.appendChild(l);
    }
    return box;
  }

  function abrirCurso(c, aulaId) {
    var corpo = est.corpo;
    corpo.textContent = "";
    var aulas = c.aulas || [];
    var atual = aulas.filter(function (a) { return a.id === aulaId; })[0] || aulas[0] || null;

    var wrap = el("div", "nf-curso");
    var voltar = el("button", "nf-voltar", "← Voltar aos cursos");
    voltar.type = "button";
    voltar.addEventListener("click", lista);
    wrap.appendChild(voltar);

    var grid = el("div", "nf-grid");
    var main = el("div", "nf-main");
    var side = el("aside", "nf-side");
    grid.appendChild(main); grid.appendChild(side);
    wrap.appendChild(grid);
    corpo.appendChild(wrap);

    function desenhar() {
      var vistas = lerVistas();
      main.textContent = ""; side.textContent = "";

      main.appendChild(el("div", "nf-curso-nome", c.titulo));
      if (!atual) {
        main.appendChild(el("div", "nf-aviso", "Este curso ainda não tem aulas."));
      } else {
        main.appendChild(player(atual));
        main.appendChild(el("h2", "nf-aula-t", atual.titulo));
        if (atual.duracao) main.appendChild(el("div", "nf-meta", "Duração: " + atual.duracao));
        if (atual.descricao) main.appendChild(el("p", "nf-desc", atual.descricao));

        var acoes = el("div", "nf-btns");
        var vista = !!vistas[atual.id];
        var bv = el("button", "nf-info", vista ? "✓ Assistida (desmarcar)" : "Marcar como assistida");
        bv.type = "button";
        bv.addEventListener("click", function () { gravarVista(atual.id, !vista); desenhar(); });
        acoes.appendChild(bv);

        var idx = aulas.indexOf(atual);
        if (idx < aulas.length - 1) {
          var prox = el("button", "nf-play", "Próxima aula ▶");
          prox.type = "button";
          prox.addEventListener("click", function () {
            gravarVista(atual.id, true);
            atual = aulas[idx + 1];
            desenhar();
          });
          acoes.appendChild(prox);
        }
        if (ehHttps(atual.material_url)) {
          var mat = el("a", "nf-info", "📎 Material da aula");
          mat.href = atual.material_url; mat.target = "_blank"; mat.rel = "noopener noreferrer";
          acoes.appendChild(mat);
        }
        main.appendChild(acoes);
      }

      var p = progresso(c);
      side.appendChild(el("h3", "", "Aulas (" + aulas.length + ")"));
      side.appendChild(el("div", "nf-meta", p.feitas + " de " + p.total + " assistidas"));
      var ul = el("div", "nf-aulas");
      aulas.forEach(function (a, i) {
        var tocando = atual && a.id === atual.id;
        var b = el("button", "nf-aula" + (tocando ? " on" : "") + (vistas[a.id] ? " vista" : ""));
        b.type = "button";
        var th = el("span", "nf-th");
        var src = miniaturaAula(a, c);
        if (src) {
          var im = el("img");
          im.src = src; im.alt = ""; im.loading = "lazy"; im.referrerPolicy = "no-referrer";
          im.addEventListener("error", function () { im.remove(); th.classList.add("sem-capa"); });
          th.appendChild(im);
        } else { th.classList.add("sem-capa"); }
        th.appendChild(el("span", "nf-th-n", String(i + 1)));
        if (a.duracao) th.appendChild(el("span", "nf-dur", a.duracao));
        if (vistas[a.id]) th.appendChild(el("span", "nf-ok", "✓"));
        b.appendChild(th);
        var tx = el("span", "nf-at");
        tx.appendChild(el("strong", "", a.titulo));
        tx.appendChild(el("small", "", tocando ? "▶ Reproduzindo agora" : (vistas[a.id] ? "Assistida" : "Aula " + (i + 1))));
        b.appendChild(tx);
        b.addEventListener("click", function () { atual = a; desenhar(); window.scrollTo({ top: 0, behavior: "smooth" }); });
        ul.appendChild(b);
      });
      var barra = el("div", "nf-prog big"), pf = el("span");
      pf.style.width = p.pct + "%"; barra.appendChild(pf);
      side.appendChild(barra);
      side.appendChild(ul);
    }
    desenhar();
    window.scrollTo(0, 0);
  }

  // ===================================================================
  // PAINEL ADMIN: cadastro de cursos e aulas
  // ===================================================================
  var adm = { cursos: [], abertos: {}, corpo: null, t: null, area: null, info: null, aviso: null };

  function cursoDados(c, extra) {
    var d = {
      id: c.id, titulo: c.titulo, descricao: c.descricao || "", capa_url: c.capa_url || "",
      categoria: c.categoria || "Cursos", destaque: !!c.destaque, ordem: c.ordem, publicado: !!c.publicado
    };
    for (var k in extra) d[k] = extra[k];
    return d;
  }
  function aulaDados(a, extra) {
    var d = {
      id: a.id, curso_id: a.curso_id, titulo: a.titulo, descricao: a.descricao || "",
      video_url: a.video_url || "", material_url: a.material_url || "", duracao: a.duracao || "",
      ordem: a.ordem, publicado: !!a.publicado
    };
    for (var k in extra) d[k] = extra[k];
    return d;
  }

  async function admin(corpo) {
    adm.corpo = corpo; adm.t = corpo.dataset.t;
    corpo.textContent = "";
    var barra = el("div", "adm-bar");
    var novo = el("button", "abtn", "+ Novo curso");
    novo.type = "button";
    novo.addEventListener("click", function () { formCurso(null); });
    adm.info = el("span", "adm-count");
    barra.appendChild(novo); barra.appendChild(adm.info);
    adm.aviso = el("div", "msg");
    adm.area = el("div", "ad-lista");
    corpo.appendChild(barra); corpo.appendChild(adm.aviso); corpo.appendChild(adm.area);
    await recarregar();
  }

  async function recarregar() {
    var t = adm.t;
    var r = await api("cursos_admin");
    if (adm.corpo.dataset.t !== t) return;
    if (!r.ok) { msg(adm.aviso, "Não foi possível carregar os cursos.", "err"); return; }
    adm.cursos = (r.data && r.data.cursos) || [];
    desenharAdmin();
  }

  function falha(texto) { msg(adm.aviso, texto, "err"); }
  function sucesso(texto) { msg(adm.aviso, texto, "ok"); }

  async function mover(itens, i, dir, salvar) {
    var j = i + dir;
    if (j < 0 || j >= itens.length) return;
    var arr = itens.slice();
    var tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
    for (var k = 0; k < arr.length; k++) {
      if (arr[k].ordem !== k + 1) {
        var r = await salvar(arr[k], k + 1);
        if (!r.ok) { falha("Não foi possível reordenar."); break; }
      }
    }
    await recarregar();
  }

  function selo(texto, cls) { return el("span", "ad-selo " + (cls || ""), texto); }

  function desenharAdmin() {
    var cs = adm.cursos;
    adm.info.textContent = cs.length + (cs.length === 1 ? " curso" : " cursos");
    adm.area.textContent = "";
    atualizarCategorias();
    if (!cs.length) { adm.area.appendChild(el("div", "vazio", "Nenhum curso ainda. Clique em “+ Novo curso”.")); return; }

    cs.forEach(function (c, i) {
      var card = el("div", "ad-curso");
      var head = el("div", "ad-head");
      head.appendChild(capa(c, "ad-thumb"));

      var info = el("div", "ad-info");
      info.appendChild(el("strong", "", c.titulo));
      var meta = el("div", "ad-meta");
      meta.appendChild(document.createTextNode((c.categoria || "Cursos") + " · " + c.aulas.length + (c.aulas.length === 1 ? " aula" : " aulas")));
      meta.appendChild(selo(c.publicado ? "Publicado" : "Rascunho", c.publicado ? "ok" : "rasc"));
      if (c.destaque) meta.appendChild(selo("★ Destaque", "dest"));
      info.appendChild(meta);
      head.appendChild(info);

      var ac = el("div", "ad-acoes");
      var sobe = el("button", "lnk", "▲"); sobe.type = "button"; sobe.title = "Subir"; sobe.disabled = i === 0;
      sobe.addEventListener("click", function () {
        mover(cs, i, -1, function (x, o) { return api("curso_salvar", cursoDados(x, { ordem: o })); });
      });
      var desce = el("button", "lnk", "▼"); desce.type = "button"; desce.title = "Descer"; desce.disabled = i === cs.length - 1;
      desce.addEventListener("click", function () {
        mover(cs, i, 1, function (x, o) { return api("curso_salvar", cursoDados(x, { ordem: o })); });
      });
      var ed = el("button", "lnk", "Editar"); ed.type = "button";
      ed.addEventListener("click", function () { formCurso(c); });
      var tg = el("button", "lnk", (adm.abertos[c.id] ? "Ocultar aulas" : "Aulas (" + c.aulas.length + ")")); tg.type = "button";
      tg.addEventListener("click", function () { adm.abertos[c.id] = !adm.abertos[c.id]; desenharAdmin(); });
      var rm = el("button", "lnk del", "Remover"); rm.type = "button";
      rm.addEventListener("click", async function () {
        var extra = c.aulas.length ? " As " + c.aulas.length + " aulas dele também serão removidas." : "";
        if (!confirm("Remover o curso “" + c.titulo + "”?" + extra + " Essa ação não pode ser desfeita.")) return;
        var r = await api("curso_remover", { id: c.id });
        if (!r.ok) { falha("Não foi possível remover o curso."); return; }
        delete adm.abertos[c.id];
        sucesso("Curso removido.");
        recarregar();
      });
      [sobe, desce, ed, tg, rm].forEach(function (b) { ac.appendChild(b); });
      head.appendChild(ac);
      card.appendChild(head);

      if (adm.abertos[c.id]) card.appendChild(painelAulas(c));
      adm.area.appendChild(card);
    });
  }

  function painelAulas(c) {
    var p = el("div", "ad-aulas");
    var topo = el("div", "ad-aulas-topo");
    topo.appendChild(el("strong", "", "Aulas do curso (na ordem em que o aluno vê)"));
    var nova = el("button", "abtn peq", "+ Nova aula"); nova.type = "button";
    nova.addEventListener("click", function () { formAula(c, null); });
    topo.appendChild(nova);
    p.appendChild(topo);

    if (!c.aulas.length) { p.appendChild(el("div", "vazio", "Este curso ainda não tem aulas.")); return p; }

    c.aulas.forEach(function (a, i) {
      var l = el("div", "ad-aula");
      l.appendChild(el("span", "ad-num", String(i + 1)));
      var t = el("div", "ad-at");
      t.appendChild(el("strong", "", a.titulo));
      var m = el("div", "ad-meta");
      m.appendChild(document.createTextNode(a.duracao ? a.duracao + " · " : ""));
      m.appendChild(selo(a.video_url ? "Com vídeo" : "Sem vídeo", a.video_url ? "ok" : "rasc"));
      if (a.material_url) m.appendChild(selo("Material", "dest"));
      m.appendChild(selo(a.publicado ? "Publicada" : "Rascunho", a.publicado ? "ok" : "rasc"));
      t.appendChild(m);
      l.appendChild(t);

      var ac = el("div", "ad-acoes");
      var sobe = el("button", "lnk", "▲"); sobe.type = "button"; sobe.disabled = i === 0;
      sobe.addEventListener("click", function () {
        mover(c.aulas, i, -1, function (x, o) { return api("aula_salvar", aulaDados(x, { ordem: o })); });
      });
      var desce = el("button", "lnk", "▼"); desce.type = "button"; desce.disabled = i === c.aulas.length - 1;
      desce.addEventListener("click", function () {
        mover(c.aulas, i, 1, function (x, o) { return api("aula_salvar", aulaDados(x, { ordem: o })); });
      });
      var ed = el("button", "lnk", "Editar"); ed.type = "button";
      ed.addEventListener("click", function () { formAula(c, a); });
      var rm = el("button", "lnk del", "Remover"); rm.type = "button";
      rm.addEventListener("click", async function () {
        if (!confirm("Remover a aula “" + a.titulo + "”? Essa ação não pode ser desfeita.")) return;
        var r = await api("aula_remover", { id: a.id });
        if (!r.ok) { falha("Não foi possível remover a aula."); return; }
        sucesso("Aula removida.");
        recarregar();
      });
      [sobe, desce, ed, rm].forEach(function (b) { ac.appendChild(b); });
      l.appendChild(ac);
      p.appendChild(l);
    });
    return p;
  }

  function atualizarCategorias() {
    var dl = $("dl-cat");
    if (!dl) return;
    dl.textContent = "";
    var vistos = {};
    adm.cursos.forEach(function (c) {
      var k = c.categoria || "Cursos";
      if (vistos[k]) return;
      vistos[k] = true;
      var o = el("option"); o.value = k; dl.appendChild(o);
    });
  }

  // ----- formulário de curso -----
  function formCurso(c) {
    $("cu-titulo-dlg").textContent = c ? "Editar curso" : "Novo curso";
    $("cu-id").value = c ? c.id : "";
    $("cu-titulo").value = c ? c.titulo : "";
    $("cu-desc").value = (c && c.descricao) || "";
    $("cu-capa").value = (c && c.capa_url) || "";
    $("cu-cat").value = c ? (c.categoria || "Cursos") : "Cursos";
    $("cu-ordem").value = c ? c.ordem : (adm.cursos.length + 1);
    $("cu-dest").checked = c ? !!c.destaque : false;
    $("cu-pub").checked = c ? !!c.publicado : true;
    msg($("msg-cu"), "");
    $("dlg-curso").showModal();
  }

  $("form-curso").addEventListener("submit", async function (e) {
    e.preventDefault();
    var id = $("cu-id").value;
    var d = {
      titulo: $("cu-titulo").value.trim(),
      descricao: $("cu-desc").value.trim(),
      capa_url: $("cu-capa").value.trim(),
      categoria: $("cu-cat").value.trim() || "Cursos",
      ordem: parseInt($("cu-ordem").value, 10) || 0,
      destaque: $("cu-dest").checked,
      publicado: $("cu-pub").checked
    };
    if (id) d.id = id;
    var r = await api("curso_salvar", d);
    if (!r.ok) {
      var er = r.data && r.data.erro;
      msg($("msg-cu"), er === "url_invalida" ? "O link da capa precisa começar com https://" : "Não foi possível salvar o curso.", "err");
      return;
    }
    $("dlg-curso").close();
    await recarregar();
    sucesso("Curso salvo.");
  });

  // ----- formulário de aula -----
  var cursoDaAula = null;
  function formAula(c, a) {
    cursoDaAula = c;
    $("au-titulo-dlg").textContent = a ? "Editar aula" : "Nova aula";
    $("au-id").value = a ? a.id : "";
    $("au-titulo").value = a ? a.titulo : "";
    $("au-desc").value = (a && a.descricao) || "";
    $("au-video").value = (a && a.video_url) || "";
    $("au-mat").value = (a && a.material_url) || "";
    $("au-dur").value = (a && a.duracao) || "";
    $("au-ordem").value = a ? a.ordem : (c.aulas.length + 1);
    $("au-pub").checked = a ? !!a.publicado : true;
    msg($("msg-au"), "");
    $("dlg-aula").showModal();
  }

  $("form-aula").addEventListener("submit", async function (e) {
    e.preventDefault();
    var id = $("au-id").value;
    var d = {
      curso_id: cursoDaAula.id,
      titulo: $("au-titulo").value.trim(),
      descricao: $("au-desc").value.trim(),
      video_url: $("au-video").value.trim(),
      material_url: $("au-mat").value.trim(),
      duracao: $("au-dur").value.trim(),
      ordem: parseInt($("au-ordem").value, 10) || 0,
      publicado: $("au-pub").checked
    };
    if (id) d.id = id;
    var r = await api("aula_salvar", d);
    if (!r.ok) {
      var er = r.data && r.data.erro;
      msg($("msg-au"), er === "url_invalida" ? "Os links do vídeo e do material precisam começar com https://" : "Não foi possível salvar a aula.", "err");
      return;
    }
    $("dlg-aula").close();
    adm.abertos[cursoDaAula.id] = true;
    await recarregar();
    sucesso("Aula salva.");
  });

  window.Cursos = { afiliado: afiliado, admin: admin };
})();

(function () {
    'use strict';
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    /* ---------- Автофокус только на компьютере ---------- */
    var finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    if (finePointer) {
        var af = $('[data-autofocus]');
        if (af) af.focus({ preventScroll: true });
    }

    /* ---------- Подтверждение опасных действий ---------- */
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);

    /* ---------- Показ/скрытие блоков ---------- */
    $$('[data-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            var target = $(btn.getAttribute('data-toggle'));
            if (!target) return;
            e.preventDefault();
            target.hidden = !target.hidden;
            if (!target.hidden) {
                target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                // на телефоне не ставим курсор: клавиатура выезжает и страница прыгает
                var f = finePointer && $(btn.getAttribute('data-focus') || 'input:not([type=hidden]):not([readonly])', target);
                if (f) setTimeout(function () { f.focus({ preventScroll: true }); }, 50);
            }
        });
    });

    /* ---------- Кликабельные строки таблиц ---------- */
    // Подробности строки результатов: на смартфоне (или если страницы подробностей нет) —
    // всплывающее окно по центру экрана; на компьютере строка открывает страницу.
    var isPhone = function () { return window.matchMedia('(max-width: 600px)').matches; };
    var detailDlg = null;
    var openDetail = function (title, html) {
        if (!detailDlg) {
            detailDlg = document.createElement('dialog');
            detailDlg.className = 'modal detail-modal';
            detailDlg.innerHTML = '<div class="modal-head"><h2 class="mt0"></h2>'
                + '<button type="button" class="icon-btn" data-close aria-label="Закрыть">✕</button></div>'
                + '<div class="detail-body"></div>'
                + '<div class="modal-actions"><button type="button" class="btn" data-close>Закрыть</button></div>';
            document.body.appendChild(detailDlg);
            detailDlg.addEventListener('click', function (e) {
                if (e.target === detailDlg || e.target.closest('[data-close]')) detailDlg.close();
            });
        }
        $('h2', detailDlg).textContent = title;
        $('.detail-body', detailDlg).innerHTML = html;
        detailDlg.showModal();
    };
    $$('tr.row-link').forEach(function (tr) {
        tr.addEventListener('click', function (e) {
            var detail = tr.getAttribute('data-detail') && $(tr.getAttribute('data-detail'));
            var href = tr.getAttribute('data-href');
            if (detail && (isPhone() || !href)) {
                e.preventDefault();
                openDetail(tr.getAttribute('data-title') || '', detail.querySelector('td').innerHTML);
                return;
            }
            if (e.target.closest('a, button, input')) return;
            if (href) window.location = href;
        });
    });

    /* ---------- Уведомления ---------- */
    $$('[data-toast-close]').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = b.closest('.toast');
            t.classList.add('hide');
            setTimeout(function () { t.remove(); }, 250);
        });
    });

    /* ---------- Готовые значения порога аттестации ---------- */
    $$('[data-preset]').forEach(function (b) {
        b.addEventListener('click', function () {
            var form = b.closest('form'), inp = form && $('input[name=pass_threshold]', form);
            if (inp && !inp.readOnly) {
                inp.value = b.getAttribute('data-preset');
                $$('[data-preset]', form).forEach(function (x) { x.classList.toggle('on', x === b); });
            }
        });
    });

    /* ---------- Копирование кода аттестации ---------- */
    $$('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy');
            var done = function () {
                btn.classList.add('copied');
                setTimeout(function () { btn.classList.remove('copied'); }, 1200);
            };
            if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, done);
            else done();
        });
    });

    /* ---------- Вкладки ---------- */
    $$('[data-tabs]').forEach(function (nav) {
        var links = $$('[data-tab]', nav);
        var show = function (name, push) {
            var found = links.some(function (l) { return l.getAttribute('data-tab') === name; });
            if (!found) name = links[0].getAttribute('data-tab');
            links.forEach(function (l) {
                var on = l.getAttribute('data-tab') === name;
                l.classList.toggle('active', on);
                var panel = document.getElementById(l.getAttribute('data-tab'));
                if (panel) panel.hidden = !on;
            });
            if (push) history.replaceState(null, '', '#' + name);
        };
        links.forEach(function (l) {
            l.addEventListener('click', function (e) {
                e.preventDefault();
                show(l.getAttribute('data-tab'), true);
            });
        });
        show(location.hash.slice(1), false);
        window.addEventListener('hashchange', function () { show(location.hash.slice(1), false); });
    });

    /* Пункты меню, ведущие на вкладку той же страницы (например, «Коды доступа») */
    var syncMenuHash = function () {
        var groups = $$('.side-nav a, .tabbar a').filter(function (a) { return a.getAttribute('href').split('#')[0] === location.pathname.split('/').pop(); });
        if (groups.length < 2) return;
        var h = location.hash;
        groups.forEach(function (a) {
            var ah = a.getAttribute('href').indexOf('#') > -1 ? '#' + a.getAttribute('href').split('#')[1] : '';
            var nav = a.closest('.side-nav, .tabbar');
            var same = $$('a', nav).filter(function (x) { return groups.indexOf(x) > -1; });
            var match = same.some(function (x) { return x.getAttribute('href').indexOf(h || '\u0000') > -1; });
            a.classList.toggle('is-active', match ? ah === h : ah === '');
        });
    };
    window.addEventListener('hashchange', syncMenuHash); syncMenuHash();

    /* ---------- «Выбрать все» баллы ---------- */
    $$('[data-select-all-group]').forEach(function (group) {
        var all = $('[data-select-all]', group);
        var boxes = $$('input[type=checkbox]:not([data-select-all])', group);
        var sync = function () { all.checked = boxes.every(function (b) { return b.checked; }); };
        all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; });
        });
        boxes.forEach(function (b) { b.addEventListener('change', sync); });
        sync();
        var form = group.hasAttribute('data-require-one') ? group.closest('form') : null;
        if (form) form.addEventListener('submit', function (e) {
            if (!boxes.some(function (b) { return b.checked; })) {
                e.preventDefault();
                alert('Выберите хотя бы один вариант баллов');
            }
        });
    });

    /* ---------- Поиск по спискам ---------- */
    var norm = function (t) { return (t || '').toLowerCase().replace(/ё/g, 'е'); };
    $$('[data-filter]').forEach(function (inp) {
        var sel = inp.getAttribute('data-filter');
        inp.addEventListener('input', function () {
            var q = norm(inp.value.trim());
            $$(sel).forEach(function (row) { row.hidden = q !== '' && norm(row.textContent).indexOf(q) < 0; });
        });
    });
    $$('[data-filter-local]').forEach(function (inp) {
        var box = inp.closest('.invite-box'), sel = inp.getAttribute('data-filter-local');
        inp.addEventListener('input', function () {
            var q = norm(inp.value.trim());
            $$(sel, box).forEach(function (row) { row.hidden = q !== '' && norm(row.textContent).indexOf(q) < 0; });
        });
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    });

    /* ---------- Выпадающие списки закрываются кликом мимо ---------- */
    document.addEventListener('click', function (e) {
        $$('details.dropdown[open]').forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
    });

    /* ---------- QR-код ---------- */
    var loadScript = function (src, ok, fail) {
        var sc = document.createElement('script');
        sc.src = src; sc.async = true; sc.onload = ok; sc.onerror = fail;
        document.head.appendChild(sc);
    };
    var renderQr = function (box) {
        if (box.getAttribute('data-done')) return;
        var text = box.getAttribute('data-qr');
        var viaGenerator = function () {
            var qr = window.qrcode(0, 'M');
            qr.addData(text); qr.make();
            box.innerHTML = qr.createSvgTag({ cellSize: 8, margin: 2, scalable: true });
            box.setAttribute('data-done', '1');
        };
        var viaImage = function () {
            box.innerHTML = '<img alt="QR-код приглашения" width="260" height="260" src="https://api.qrserver.com/v1/create-qr-code/?size=520x520&margin=8&data=' + encodeURIComponent(text) + '">';
            box.setAttribute('data-done', '1');
        };
        if (window.qrcode) return viaGenerator();
        loadScript('https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js', viaGenerator, function () {
            loadScript('https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js', viaGenerator, viaImage);
        });
    };

    /* ---------- Сканер QR-кода приглашения ---------- */
    $$('[data-scan]').forEach(function (btn) {
        var dlg = $(btn.getAttribute('data-scan'));
        var input = $(btn.getAttribute('data-scan-target'));
        var video = $('video', dlg), status = $('[data-scan-status]', dlg);
        var stream = null, timer = null, canvas = document.createElement('canvas');
        var stop = function () {
            if (timer) cancelAnimationFrame(timer);
            timer = null;
            if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
            stream = null;
        };
        var found = function (text) {
            stop();
            var m = /[?&]code=([A-Za-z0-9]+)/.exec(text);
            var code = (m ? m[1] : text).trim().toUpperCase();
            if (/^https?:/i.test(text) && m) { window.location = text; return; }
            input.value = code;
            dlg.close();
            if (input.form && !btn.hasAttribute("data-scan-fill")) input.form.submit();
        };
        var loop = function (detector) {
            if (!stream) return;
            if (video.readyState >= 2) {
                if (detector) {
                    detector.detect(video).then(function (codes) {
                        if (codes.length) found(codes[0].rawValue); else timer = requestAnimationFrame(function () { loop(detector); });
                    }).catch(function () { timer = requestAnimationFrame(function () { loop(detector); }); });
                    return;
                }
                canvas.width = video.videoWidth; canvas.height = video.videoHeight;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0);
                var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                var res = window.jsQR && window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
                if (res && res.data) { found(res.data); return; }
            }
            timer = requestAnimationFrame(function () { loop(detector); });
        };
        var start = function () {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                status.textContent = 'Браузер не даёт доступ к камере. Введите код вручную.';
                return;
            }
            status.textContent = 'Наведите камеру на QR-код приглашения';
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
                stream = s; video.srcObject = s; video.setAttribute('playsinline', ''); video.play();
                if ('BarcodeDetector' in window) {
                    loop(new window.BarcodeDetector({ formats: ['qr_code'] }));
                } else if (window.jsQR) {
                    loop(null);
                } else {
                    loadScript('https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js', function () { loop(null); }, function () {
                        status.textContent = 'Не удалось загрузить сканер. Введите код вручную.';
                    });
                }
            }).catch(function () {
                status.textContent = 'Нет доступа к камере. Разрешите доступ в настройках браузера или введите код вручную.';
            });
        };
        btn.addEventListener('click', function () { dlg.showModal(); start(); });
        dlg.addEventListener('close', stop);
    });

    /* ---------- Модальные окна ---------- */
    var openDialog = function (dlg) {
        if (!dlg) return;
        if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
        $$('[data-qr]', dlg).forEach(renderQr);
    };
    $$('[data-dialog]').forEach(function (b) {
        b.addEventListener('click', function () { openDialog($(b.getAttribute('data-dialog'))); });
    });
    $$('dialog.modal').forEach(function (dlg) {
        $$('[data-dialog-close]', dlg).forEach(function (b) {
            b.addEventListener('click', function () { dlg.close ? dlg.close() : dlg.removeAttribute('open'); });
        });
        dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    });
    if (location.hash === '#settings') openDialog($('#settings-dialog'));
    $$('[role=button][data-dialog]').forEach(function (b) {
        b.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); b.click(); } });
    });

    /* ---------- Порог сдачи ---------- */
    var thr = $('[data-threshold]:not([data-wizard])');
    if (thr && thr.tagName === 'FORM') {
        var num = $('input[type=number]', thr), rng = $('[data-threshold-range]', thr);
        var hintEl = $('[data-threshold-hint] b', thr), maxPts = +thr.getAttribute('data-max');
        var upd = function (v, from) {
            v = Math.max(50, Math.min(100, parseInt(v, 10) || 50));
            if (from !== 'num') num.value = v;
            if (from !== 'rng') rng.value = v;
            if (hintEl) hintEl.textContent = String(Math.ceil(maxPts * v) / 100);
        };
        num.addEventListener('input', function () { if (num.value !== '') upd(num.value, 'num'); });
        rng.addEventListener('input', function () { upd(rng.value, 'rng'); });
        $$('[data-set]', thr).forEach(function (b) {
            b.addEventListener('click', function () { upd(b.getAttribute('data-set')); });
        });
    }

    /* ---------- Мои курсы: выбор курса ---------- */
    $$('[data-pick]').forEach(function (b) {
        b.addEventListener('click', function () {
            var target = $(b.getAttribute('data-pick'));
            $$('[data-pick]').forEach(function (x) { x.classList.toggle('active', x === b); });
            $$('.my-course-panel').forEach(function (p) { p.hidden = p !== target; });
            if (!finePointer && target) target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });

    /* ---------- Прохождение урока ---------- */
    var lp = $('[data-lesson-player]');
    if (lp) (function () {
        var lessonId = lp.getAttribute('data-lesson');
        var sections = $$('.lp-section', lp), navBtns = $$('.lp-nav [data-goto]', lp);
        var bar = $('[data-lp-bar]'), countBadge = $('[data-lp-count]');
        var total = +lp.getAttribute('data-total') || 0;
        var cur = +lp.getAttribute('data-start') || 0;
        var mode = 'lr';
        try { mode = localStorage.getItem('lessonNavMode') || 'lr'; } catch (e) {}
        $$('input[name=lp-mode]', lp).forEach(function (r) {
            r.checked = r.value === mode;
            r.addEventListener('change', function () {
                mode = r.value;
                try { localStorage.setItem('lessonNavMode', mode); } catch (e) {}
            });
        });

        var save = function (itemId, done) {
            var body = new URLSearchParams({ lesson: lessonId, section: cur });
            if (itemId) { body.set('item', itemId); body.set('done', done ? '1' : ''); }
            fetch('lesson_progress.php', { method: 'POST', body: body, credentials: 'same-origin' }).catch(function () {});
        };
        var sectionDone = function (i) {
            var items = $$('.lp-item', sections[i]);
            return items.every(function (it) { return it.classList.contains('done'); });
        };
        var refresh = function () {
            var done = $$('.lp-item.done', lp).length;
            if (bar) bar.style.width = (total ? done / total * 100 : 0) + '%';
            if (countBadge) countBadge.textContent = 'Пройдено ' + done + ' из ' + total;
            sections.forEach(function (sec, i) {
                var items = $$('.lp-item', sec), d = $$('.lp-item.done', sec).length;
                var complete = d === items.length;
                navBtns[i].classList.toggle('done', complete);
                navBtns[i].classList.toggle('active', i === cur);
                $('.nav-num', navBtns[i]).textContent = complete ? '✓' : (i + 1);
                var c = $('[data-sec-count="' + i + '"]', lp);
                if (c) c.textContent = d + '/' + items.length;
            });
        };
        var go = function (i, silent) {
            if (i < 0 || i >= sections.length) return;
            cur = i;
            sections.forEach(function (s, k) { s.hidden = k !== cur; });
            refresh();
            if (!silent) {
                save(null);
                var top = lp.getBoundingClientRect().top + window.scrollY - 70;
                if (window.scrollY > top) window.scrollTo({ top: top, behavior: 'smooth' });
            }
        };
        var setDone = function (li, done) {
            if (li.classList.contains('done') === done) return;
            li.classList.toggle('done', done);
            $('.lp-check', li).setAttribute('aria-pressed', done ? 'true' : 'false');
            var content = $('.lp-content', li);
            if (content && done) content.hidden = true;
            save(li.getAttribute('data-item'), done);
            refresh();
            // все пункты подраздела отмечены — переходим к следующему
            if (done && sectionDone(cur) && cur < sections.length - 1) {
                var from = cur;
                setTimeout(function () { if (cur === from) go(cur + 1); }, 650);
            }
        };

        $$('.lp-item', lp).forEach(function (li) {
            $('.lp-check', li).addEventListener('click', function () { setDone(li, !li.classList.contains('done')); });
            $('.lp-item-title', li).addEventListener('click', function () {
                var content = $('.lp-content', li);
                if (content && !li.classList.contains('done')) content.hidden = !content.hidden;
            });
        });
        navBtns.forEach(function (b) { b.addEventListener('click', function () { go(+b.getAttribute('data-goto')); }); });
        $$('[data-lp-prev]', lp).forEach(function (b) { b.addEventListener('click', function () { go(cur - 1); }); });
        $$('[data-lp-next]', lp).forEach(function (b) { b.addEventListener('click', function () { go(cur + 1); }); });

        document.addEventListener('keydown', function (e) {
            if (e.target.closest('input:not([type=radio]), textarea, select, dialog[open]') || e.ctrlKey || e.metaKey || e.altKey) return;
            var sec = sections[cur];
            if (e.key === 'Enter') {
                var focused = e.target.closest && e.target.closest('.lp-item');
                var li = focused && !focused.classList.contains('done') ? focused : $$('.lp-item', sec).filter(function (x) { return !x.classList.contains('done'); })[0];
                if (li) { e.preventDefault(); setDone(li, true); }
            } else if (e.key === 'Backspace') {
                var doneItems = $$('.lp-item.done', sec);
                if (doneItems.length) { e.preventDefault(); setDone(doneItems[doneItems.length - 1], false); }
            } else if ((mode === 'lr' && e.key === 'ArrowRight') || (mode === 'ud' && e.key === 'ArrowDown')) {
                e.preventDefault(); go(cur + 1);
            } else if ((mode === 'lr' && e.key === 'ArrowLeft') || (mode === 'ud' && e.key === 'ArrowUp')) {
                e.preventDefault(); go(cur - 1);
            }
        });
        go(cur, true);
    })();

    /* ---------- Мастер оценки ---------- */
    var wiz = $('[data-wizard]');
    if (wiz) {
        var steps = $$('[data-step]', wiz);
        var navBtns = $$('[data-goto]', wiz);
        var prev = $('[data-prev]', wiz), next = $('[data-next]', wiz);
        var save = $('[data-save]', wiz), hint = $('[data-save-hint]', wiz);
        var bar = $('[data-progress-bar]', wiz), ptext = $('[data-progress-text]', wiz);
        var cur = 0;

        var valueOf = function (i) {
            var c = $('input[type=radio]:checked', steps[i]);
            return c ? c.value : null;
        };
        var refresh = function () {
            var done = 0;
            steps.forEach(function (s, i) {
                var v = valueOf(i);
                if (v !== null) done++;
                navBtns[i].classList.toggle('done', v !== null);
                navBtns[i].classList.toggle('active', i === cur);
                $('[data-nav-val="' + i + '"]', wiz).textContent = v === null ? '' : v;
                s.hidden = i !== cur;
            });
            prev.disabled = cur === 0;
            next.hidden = cur === steps.length - 1;
            var complete = done === steps.length;
            showVerdict(complete);
            save.disabled = !complete;
            hint.hidden = complete;
            bar.style.width = (done / steps.length * 100) + '%';
            ptext.textContent = 'Оценено ' + done + ' из ' + steps.length;
        };
        var verdict = $('[data-verdict]', wiz), wasComplete = null;
        var threshold = +wiz.getAttribute('data-threshold') || 0;
        var showVerdict = function (complete) {
            if (!complete) { verdict.hidden = true; wasComplete = false; return; }
            var sum = 0;
            steps.forEach(function (s, i) { sum += parseFloat(valueOf(i)) || 0; });
            var pctv = Math.round(sum / steps.length * 1000) / 10;
            var ok = pctv >= threshold;
            verdict.className = 'verdict ' + (ok ? 'pass' : 'fail');
            $('[data-verdict-icon]', verdict).textContent = ok ? '✓' : '✗';
            $('[data-verdict-title]', verdict).textContent = ok ? 'Сдал' : 'Не сдал';
            $('[data-verdict-sub]', verdict).textContent =
                (Math.round(sum * 100) / 100) + ' из ' + steps.length + ' баллов · ' +
                String(pctv).replace('.', ',') + '% при пороге ' + threshold + '%';
            verdict.hidden = false;
            if (wasComplete === false) {          // только что выставлен последний критерий
                verdict.classList.add('pop');
                setTimeout(function () { verdict.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 60);
            }
            wasComplete = true;
        };
        var go = function (i) {
            cur = Math.max(0, Math.min(steps.length - 1, i));
            refresh();
            var top = wiz.getBoundingClientRect().top + window.scrollY - 12;
            if (window.scrollY > top) window.scrollTo({ top: top, behavior: 'smooth' });
        };

        prev.addEventListener('click', function () { clearTimeout(advanceTimer); go(cur - 1); });
        next.addEventListener('click', function () { clearTimeout(advanceTimer); go(cur + 1); });
        navBtns.forEach(function (b) {
            b.addEventListener('click', function () { clearTimeout(advanceTimer); go(+b.getAttribute('data-goto')); });
        });
        // выбор балла — сразу к следующему неоценённому критерию (кнопку «Далее» нажимать не нужно);
        // сохраняется результат только кнопкой «Сохранить»
        var advanceTimer = null;
        var advance = function () {
            clearTimeout(advanceTimer);
            var from = cur;
            advanceTimer = setTimeout(function () {
                if (cur !== from) return;            // инструктор уже сам перешёл
                var n = steps.length, target = -1;
                for (var k = 1; k < n; k++) {
                    var j = (from + k) % n;
                    if (valueOf(j) === null) { target = j; break; }
                }
                if (target >= 0) go(target);
                else if (!verdict.hidden) verdict.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 450);
        };
        $$('input[type=radio]', wiz).forEach(function (r) {
            r.addEventListener('change', refresh);
            r.addEventListener('click', advance);   // и при повторном нажатии на тот же балл
            r.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
        });
        document.addEventListener('keydown', function (e) {
            if (e.target.matches('input[type=text], textarea')) return;
            if (e.key === 'ArrowRight') go(cur + 1);
            if (e.key === 'ArrowLeft') go(cur - 1);
        });
        wiz.addEventListener('submit', function (e) {
            if (save.disabled) {
                e.preventDefault();
                var firstEmpty = steps.findIndex(function (s, i) { return valueOf(i) === null; });
                if (firstEmpty >= 0) go(firstEmpty);
            }
        });

        // начать с первого неоценённого критерия
        var first = steps.findIndex(function (s, i) { return valueOf(i) === null; });
        cur = first >= 0 ? first : 0;
        wiz.classList.add('ready');
        refresh();
    }
    /* Переключатели, которые сохраняются сразу */
    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-autosubmit]')) { var f = e.target.form; setTimeout(function () { f.submit(); }, 120); }
    });

    /* Тень у верхней панели при прокрутке */
    var topbar = $('body.app .topbar');
    if (topbar) {
        var onScroll = function () { topbar.classList.toggle('scrolled', window.scrollY > 4); };
        window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
    }

    /* ---------- Администрирование: карточка пользователя во всплывающем окне ---------- */
    (function () {
        var modal = $('#user-modal');
        if (!modal) return;
        var body = $('[data-modal-body]', modal);
        var openUser = function (id, tab) {
            body.innerHTML = '<p class="muted pad">Загрузка…</p>';
            if (modal.showModal && !modal.open) modal.showModal(); else modal.setAttribute('open', '');
            var url = modal.getAttribute('data-src') + '?id=' + encodeURIComponent(id) + (tab ? '&ut=' + encodeURIComponent(tab) : '')
                + '&ret=' + encodeURIComponent(modal.getAttribute('data-ret') || '');
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    body.innerHTML = html;
                    // сообщение после сохранения показываем внутри окна
                    var fl = $('.container > .alert');
                    var head = $('.mtabs', body);
                    if (tab && fl && head) head.parentNode.insertBefore(fl, head.nextSibling);
                })
                .catch(function () { body.innerHTML = '<p class="muted pad">Не удалось загрузить. Проверьте соединение.</p>'; });
        };
        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-user-card]');
            if (el) { openUser(el.getAttribute('data-user-card')); return; }
            if (!modal.contains(e.target)) return;
            if ((el = e.target.closest('[data-mtab]'))) {
                var name = el.getAttribute('data-mtab');
                $$('[data-mtab]', modal).forEach(function (b) { b.classList.toggle('is-on', b === el); });
                $$('[data-panel]', modal).forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
            } else if ((el = e.target.closest('[data-dialog-close]'))) {
                modal.close();
            } else if ((el = e.target.closest('[data-copy]'))) {
                var t = el.getAttribute('data-copy');
                var done = function () { el.classList.add('copied'); setTimeout(function () { el.classList.remove('copied'); }, 1200); };
                if (navigator.clipboard) navigator.clipboard.writeText(t).then(done, done); else done();
            }
        });
        var openId = modal.getAttribute('data-open-user');
        if (openId) {
            openUser(openId, modal.getAttribute('data-open-tab') || 'main');
            try { history.replaceState(null, '', location.pathname + location.search.replace(/[?&](u|ut)=[^&]*/g, '').replace(/^&/, '?') + location.hash); } catch (e) {}
        }
    })();

    /* Способ подсчёта итога: показываем подсказку для выбранного режима */
    $$('[data-verdict-mode]').forEach(function (fs) {
        var form = fs.closest('form') || document;
        var sync = function () {
            var r = $('input[name=verdict_mode]:checked', fs), mode = r ? r.value : 'avg';
            $$('[data-mode-hint]', form).forEach(function (h) { h.hidden = h.getAttribute('data-mode-hint') !== mode; });
            var nh = $('[data-need-hint]', form); if (nh) nh.hidden = mode !== 'stations';
        };
        fs.addEventListener('change', sync); sync();
    });

    /* Порог аттестации: сколько станций нужно сдать при выбранном проценте */
    $$('[data-need-hint]').forEach(function (hint) {
        var form = hint.closest('form') || document, n = +hint.getAttribute('data-st');
        var input = $('input[name=pass_threshold]', form), out = $('[data-need-text]', hint);
        if (!input || !out || !n) return;
        var word = function (k) { var a = k % 100, b = k % 10; return (a > 10 && a < 20) ? 'станций' : (b === 1 ? 'станции' : (b > 1 && b < 5 ? 'станций' : 'станций')); };
        var upd = function () {
            var t = Math.max(50, Math.min(100, parseInt(input.value, 10) || 50));
            var need = Math.max(1, Math.ceil(n * t / 100 - 1e-9));
            out.textContent = need >= n ? 'нужно сдать все станции (' + n + ')' : 'нужно сдать ' + need + ' из ' + n + ' ' + word(n);
        };
        input.addEventListener('input', upd);
        form.addEventListener('click', function (e) { if (e.target.closest('[data-preset]')) setTimeout(upd, 0); });
    });

    /* ---------- Приложение на телефоне и push-уведомления ---------- */
    var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var isStandalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
    var pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    if (isStandalone) document.documentElement.classList.add('standalone');

    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
    }

    /* Установка: Chrome на Android присылает событие, после которого можно показать системное окно */
    var installEvt = null;
    function installRender() {
        $$('[data-install]').forEach(function (box) {
            var st = $('[data-install-status]', box), btn = $('[data-install-btn]', box), hint = $('[data-install-hint]', box);
            if (isStandalone) {
                st.textContent = 'Приложение установлено — вы открыли его с главного экрана.';
                btn.hidden = true; hint.hidden = true; box.classList.add('is-done');
            } else if (installEvt) {
                st.textContent = 'Можно установить СМСО на этот телефон или компьютер: значок появится на главном экране, сайт откроется без адресной строки.';
                btn.hidden = false; hint.hidden = true;
            } else if (isIOS) {
                st.textContent = 'На iPhone приложение устанавливается через Safari.';
                hint.textContent = 'Откройте сайт в Safari → кнопка «Поделиться» → «На экран „Домой“». Затем запустите СМСО с главного экрана.';
                btn.hidden = true; hint.hidden = false;
            } else {
                st.textContent = 'Установить можно из меню браузера.';
                hint.textContent = 'Chrome: меню ⋮ → «Установить приложение» или «Добавить на главный экран». Если пункта нет — откройте сайт по адресу https://.';
                btn.hidden = true; hint.hidden = false;
            }
        });
    }
    window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); installEvt = e; installRender(); });
    window.addEventListener('appinstalled', function () { installEvt = null; isStandalone = true; installRender(); });
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-install-btn]');
        if (!b || !installEvt) return;
        installEvt.prompt();
        installEvt.userChoice.then(function () { installEvt = null; installRender(); });
    });
    installRender();

    function b64ToBytes(s) {
        var pad = '='.repeat((4 - s.length % 4) % 4);
        var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function pushInit() {
        var box = $('[data-push]');
        if (!box) return;
        var q = function (s) { return box.querySelector(s); };
        var status = q('[data-push-status]'), acts = q('[data-push-actions]'), hint = q('[data-push-hint]');
        var bOn = q('[data-push-on]'), bOff = q('[data-push-off]'), bTest = q('[data-push-test]');
        var say = function (t) { status.textContent = t; };
        var tip = function (t) { hint.textContent = t || ''; hint.hidden = !t; };
        var api = function (a, sub) {
            return fetch(box.getAttribute('data-api'), {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ a: a, sub: sub ? sub.toJSON() : null, token: box.getAttribute('data-token') })
            }).then(function (r) {
                return r.text().then(function (t) {
                    try { return JSON.parse(t); }
                    catch (e) { return { ok: false, error: 'сайт ответил не так, как ожидалось (HTTP ' + r.status + '): ' + t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160) }; }
                });
            }).catch(function (e) { return { ok: false, error: 'нет связи с сайтом (' + e.message + ')' }; });
        };
        var showDiag = function (d) {
            if (!d) return;
            tip('Диагностика (пришлите разработчику): ' + Object.keys(d).map(function (k) { return k + ': ' + d[k]; }).join(' · '));
        };
        if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            say('Уведомления работают только при входе по защищённому адресу https://.');
            tip('Откройте сайт по адресу, начинающемуся с https://, и включите уведомления здесь.');
            return;
        }
        if (!pushSupported) {
            if (isIOS && !isStandalone) {
                say('На iPhone уведомления работают только в установленном приложении.');
                tip('Откройте сайт в Safari → «Поделиться» → «На экран „Домой“». Запустите СМСО с главного экрана и включите уведомления здесь. Нужна iOS 16.4 или новее.');
            } else say('Этот браузер не поддерживает уведомления. Откройте сайт в Chrome.');
            return;
        }
        if (!box.getAttribute('data-key')) { say('Сервер не смог подготовить уведомления.'); return; }
        var key = b64ToBytes(box.getAttribute('data-key'));

        function render(sub) {
            acts.hidden = false;
            if (Notification.permission === 'denied') {
                say('Уведомления запрещены для этого сайта.');
                tip('Разрешите их: значок слева от адреса сайта → «Уведомления» → «Разрешить». Для установленного приложения: настройки телефона → Приложения → СМСО → Уведомления.');
                bOn.hidden = true; bOff.hidden = true; bTest.hidden = true;
                return;
            }
            tip('');
            bOn.hidden = !!sub; bOff.hidden = !sub; bTest.hidden = !sub;
            say(sub ? 'Включены на этом устройстве. Нажмите «Проверить», чтобы убедиться, что уведомление приходит.' : 'Выключены на этом устройстве.');
            var done = false;
            try { done = !!sub && localStorage.getItem('pushTested') === sub.endpoint; } catch (e) {}
            box.classList.toggle('is-done', done);
        }
        navigator.serviceWorker.register('sw.js').catch(function () {});
        navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.getSubscription().then(function (sub) {
                if (sub) api('sub', sub); // напоминаем серверу о подписке (повторять безопасно)
                render(sub);
                bOn.addEventListener('click', function () {
                    bOn.disabled = true; say('Запрашиваем разрешение…');
                    Notification.requestPermission().then(function (p) {
                        if (p !== 'granted') { bOn.disabled = false; render(null); return; }
                        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }).then(function (s) {
                            return api('sub', s).then(function (r) {
                                bOn.disabled = false;
                                if (!r.ok) { say('Не удалось сохранить подписку: ' + (r.error || '')); return; }
                                render(s);
                            });
                        });
                    }).catch(function (err) { bOn.disabled = false; say('Не удалось включить: ' + err.message); });
                });
                bOff.addEventListener('click', function () {
                    reg.pushManager.getSubscription().then(function (s) {
                        if (!s) return render(null);
                        return api('unsub', s).then(function () { return s.unsubscribe(); }).then(function () { render(null); });
                    });
                });
                bTest.addEventListener('click', function () {
                    bTest.disabled = true; say('Отправляем тестовое уведомление…');
                    reg.pushManager.getSubscription().then(function (s) { return api('test', s); }).then(function (r) {
                        bTest.disabled = false;
                        say(r.ok ? 'Отправлено — проверьте шторку уведомлений.' : 'Не отправилось: ' + (r.error || 'ошибка'));
                        if (r.ok) reg.pushManager.getSubscription().then(function (s) {
                            try { localStorage.setItem('pushTested', s.endpoint); } catch (e) {}
                            setTimeout(function () { box.classList.add('is-done'); }, 2500);
                        });
                        if (!r.ok) { if (r.diag) showDiag(r.diag); else api('diag', null).then(function (x) { showDiag(x && x.diag); }); }
                    });
                });
            });
        }).catch(function () { say('Не удалось запустить фоновый обработчик. Обновите страницу.'); });

        // подписку обновил сам браузер — сообщаем серверу
        navigator.serviceWorker.addEventListener('message', function (e) {
            if (e.data && e.data.type === 'resubscribe') {
                navigator.serviceWorker.ready.then(function (reg) {
                    reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }).then(function (s) { api('sub', s); });
                });
            }
        });
    }
    pushInit();

    /* Подсказка на главной: уведомления ещё не включены */
    (function () {
        var n = $('[data-push-nudge]');
        if (!n || !pushSupported || Notification.permission !== 'default' || location.protocol !== 'https:') return;
        try { if (localStorage.getItem('pushNudgeHidden')) return; } catch (e) {}
        navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
            if (!sub) n.hidden = false;
        });
        var close = $('[data-nudge-close]', n);
        if (close) close.addEventListener('click', function () { n.hidden = true; try { localStorage.setItem('pushNudgeHidden', '1'); } catch (e) {} });
    })();
})();


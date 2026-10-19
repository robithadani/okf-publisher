(function () {
	'use strict';

	const cfg = window.OKF;
	if (!cfg) { return; }
	const $ = (id) => document.getElementById(id);

	function call(action, nonce, data) {
		const body = new URLSearchParams(Object.assign({ action: action, _ajax_nonce: nonce }, data));
		return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then((res) => {
				if (!res.ok) { throw new Error('server merespons ' + res.status); }
				return res.json();
			})
			.then((json) => {
				if (!json.success) { throw new Error(json.data || 'permintaan gagal'); }
				return json.data || {};
			});
	}

	const gen = $('okf-genkey');
	if (gen) {
		gen.addEventListener('click', function () {
			const b = crypto.getRandomValues(new Uint8Array(24));
			$('okf_key').value = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
		});
	}

	function legacyCopy(text) {
		const ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild(ta);
		ta.select();
		let ok = false;
		try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
		document.body.removeChild(ta);
		return ok;
	}
	function copyText(text) {
		const legacy = () => (legacyCopy(text) ? Promise.resolve() : Promise.reject(new Error('gagal menyalin')));
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text).catch(legacy);
		}
		return legacy();
	}
	document.querySelectorAll('.okf-copy').forEach(function (btn) {
		btn.addEventListener('click', function () {
			const original = btn.textContent;
			copyText(btn.dataset.copy).then(function () {
				btn.textContent = 'Tersalin';
				$('okf-copy-status').textContent = 'Tersalin ke clipboard';
			}).catch(function () {
				btn.textContent = 'Gagal, salin manual';
			}).finally(function () {
				setTimeout(function () { btn.textContent = original; }, 1500);
			});
		});
	});

	const rebuild = $('okf-rebuild');
	const progress = $('okf-progress');
	if (rebuild) {
		rebuild.addEventListener('click', async function () {
			rebuild.disabled = true;
			const step = (data) => call('okf_rebuild', cfg.nonceRebuild, data);
			try {
				progress.textContent = 'Menyiapkan…';
				const init = await step({ step: 'init' });
				let offset = 0, written = 0;
				while (offset < init.total) {
					written += (await step({ step: 'batch', offset: offset })).written;
					offset += 25;
					progress.textContent = Math.min(offset, init.total) + '/' + init.total + ' dokumen…';
				}
				await step({ step: 'finish', written: written });
				progress.textContent = 'Selesai: ' + written + ' dokumen ditulis.';
				setTimeout(function () { location.reload(); }, 1000);
			} catch (e) {
				progress.textContent = 'Gagal: ' + e.message + '. Coba ulangi, atau jalankan wp okf rebuild.';
				rebuild.disabled = false;
			}
		});
	}

	const tree = $('okf-tree');
	if (!tree) { return; }
	const elMeta = $('okf-meta'), elFm = $('okf-fm'), elBody = $('okf-body');
	const browse = (path) => call('okf_browse', cfg.nonceBrowse, { path: path });

	function icon(name) {
		const i = document.createElement('span');
		i.className = 'dashicons dashicons-' + name;
		i.setAttribute('aria-hidden', 'true');
		return i;
	}

	function splitDoc(text) {
		const m = text.match(/^---\n([\s\S]*?)\n---\n?/);
		return m ? { fm: m[1], body: text.slice(m[0].length).replace(/^\n+/, '') } : { fm: '', body: text };
	}

	function setActive(btn) {
		tree.querySelectorAll('[aria-current]').forEach((n) => n.removeAttribute('aria-current'));
		btn.setAttribute('aria-current', 'true');
	}

	async function openDoc(path, btn) {
		setActive(btn);
		elMeta.textContent = 'Memuat ' + path + '…';
		try {
			const d = await browse(path);
			const parts = splitDoc(d.content);
			elFm.textContent = parts.fm || '(tanpa frontmatter)';
			elBody.textContent = parts.body + (d.truncated ? '\n\n… (pratinjau dipotong)' : '');
			elMeta.textContent = '/' + path + ' · ' + Math.max(1, Math.round(d.size / 1024)) + ' KB';
			if (cfg.public) {
				const a = document.createElement('a');
				a.href = cfg.base + path;
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = 'Buka file';
				elMeta.appendChild(document.createTextNode(' · '));
				elMeta.appendChild(a);
			}
		} catch (e) {
			elMeta.textContent = 'Gagal memuat: ' + e.message;
		}
	}

	function fileButton(label, path) {
		const b = document.createElement('button');
		b.type = 'button';
		b.className = 'okf-node';
		b.appendChild(icon(label === 'index.md' ? 'list-view' : 'media-text'));
		b.appendChild(document.createTextNode(label));
		b.addEventListener('click', () => openDoc(path, b));
		return b;
	}

	function folderNode(d) {
		const wrap = document.createElement('div');
		const head = document.createElement('button');
		const kids = document.createElement('div');
		const arrow = icon('arrow-right-alt2');
		const count = document.createElement('span');
		let loaded = false;
		head.type = 'button';
		head.className = 'okf-node';
		head.setAttribute('aria-expanded', 'false');
		head.appendChild(arrow);
		head.appendChild(document.createTextNode(d.dir + '/'));
		count.className = 'okf-count';
		count.textContent = d.count;
		head.appendChild(count);
		kids.className = 'okf-children';
		kids.hidden = true;
		head.addEventListener('click', async function () {
			const open = head.getAttribute('aria-expanded') !== 'true';
			head.setAttribute('aria-expanded', String(open));
			arrow.className = 'dashicons dashicons-arrow-' + (open ? 'down' : 'right') + '-alt2';
			kids.hidden = !open;
			if (!open || loaded) { return; }
			loaded = true;
			kids.textContent = 'Memuat…';
			try {
				const r = await browse(d.dir + '/');
				kids.textContent = '';
				const list = document.createElement('div');
				const items = r.files.map((f) => fileButton(f, d.dir + '/' + f));
				if (items.length > 12) {
					const q = document.createElement('input');
					q.type = 'search';
					q.className = 'okf-filter';
					q.setAttribute('aria-label', 'Cari dokumen di ' + d.dir);
					q.placeholder = 'Cari dokumen…';
					q.addEventListener('input', function () {
						const term = q.value.trim().toLowerCase();
						items.forEach((b) => { b.hidden = term !== '' && !b.textContent.toLowerCase().includes(term); });
					});
					kids.appendChild(q);
				}
				items.forEach((b) => list.appendChild(b));
				kids.appendChild(list);
			} catch (e) {
				kids.textContent = 'Gagal memuat: ' + e.message;
				loaded = false;
			}
		});
		wrap.appendChild(head);
		wrap.appendChild(kids);
		return wrap;
	}

	browse('').then(function (root) {
		tree.textContent = '';
		const idx = root.files.includes('index.md') ? fileButton('index.md', 'index.md') : null;
		if (idx) { tree.appendChild(idx); }
		root.dirs.forEach((d) => tree.appendChild(folderNode(d)));
		root.files.filter((f) => f !== 'index.md').forEach((f) => tree.appendChild(fileButton(f, f)));
		if (idx) { idx.click(); }
	}).catch(function (e) {
		tree.textContent = 'Gagal memuat bundle: ' + e.message;
	});
})();

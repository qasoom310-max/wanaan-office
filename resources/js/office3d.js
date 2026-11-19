/*
 * The 3D office: one pod per agent on a round floor, grouped by team. Everything that
 * moves is real: a pod lights up only while its agent is working (with its real step
 * above it), a light travels between two pods only while one is answering the other,
 * the meeting table glows only during a team meeting, column height is the agent's real
 * spending this month, and amber orbs are its real questions waiting for the owner.
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { CSS2DRenderer, CSS2DObject } from 'three/examples/jsm/renderers/CSS2DRenderer.js';

const holder = document.getElementById('scene');
const dataEl = document.getElementById('scene-data');

const webglWorks = () => {
    try {
        const c = document.createElement('canvas');
        return !!(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
    } catch (e) {
        return false;
    }
};

if (holder && dataEl && !webglWorks()) {
    document.getElementById('scene-fallback').hidden = false;
} else if (holder && dataEl) {
    const data = JSON.parse(dataEl.textContent);
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

    const TEAM_COLOURS = {
        front: 0x3fc1b6, accounts: 0x7fb4ff, sections: 0xffc46b, it: 0xb69cff, marketing: 0xff8fb1, planning: 0x8fe38b,
    };
    const WORK = new THREE.Color(0x9ff7ee);
    const STUCK = new THREE.Color(0xf0a050);
    const WAIT = new THREE.Color(0x6c7a88);

    // ── Renderer, camera, controls ──────────────────────────────────────────
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.shadowMap.enabled = true;
    holder.appendChild(renderer.domElement);

    const labels = new CSS2DRenderer();
    labels.domElement.style.position = 'absolute';
    labels.domElement.style.inset = '0';
    labels.domElement.style.pointerEvents = 'none';
    holder.appendChild(labels.domElement);

    const scene = new THREE.Scene();
    scene.fog = new THREE.Fog(0x0b1117, 30, 70);
    const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 200);
    camera.position.set(0, 17, 24);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.minDistance = 10;
    controls.maxDistance = 42;
    controls.maxPolarAngle = Math.PI * 0.44;
    controls.target.set(0, 1.5, 0);
    controls.autoRotate = !still;
    controls.autoRotateSpeed = 0.35;

    // ── Light that follows Bahrain time ─────────────────────────────────────
    const ambient = new THREE.AmbientLight(0xbfd6e6, 0.5);
    const sun = new THREE.DirectionalLight(0xffffff, 1.2);
    sun.position.set(12, 20, 8);
    sun.castShadow = true;
    sun.shadow.mapSize.set(1024, 1024);
    scene.add(ambient, sun);
    const nightGlow = new THREE.PointLight(0x3fc1b6, 0, 40);
    nightGlow.position.set(0, 6, 0);
    scene.add(nightGlow);
    const setHour = (hour) => {
        const day = hour >= 6 && hour < 18;
        ambient.intensity = day ? 0.65 : 0.25;
        sun.intensity = day ? 1.3 : 0.25;
        sun.color.set(day ? 0xfff3e0 : 0x8fa8ff);
        nightGlow.intensity = day ? 0 : 1.4;
    };
    setHour(new Date().getHours());

    // ── The floor and the meeting table ─────────────────────────────────────
    const floor = new THREE.Mesh(new THREE.CircleGeometry(15, 96), new THREE.MeshStandardMaterial({ color: 0x15202a, roughness: 0.9, metalness: 0.1 }));
    floor.rotation.x = -Math.PI / 2;
    floor.receiveShadow = true;
    scene.add(floor);
    for (const r of [5, 9, 13]) {
        const ring = new THREE.Mesh(new THREE.RingGeometry(r - 0.02, r + 0.02, 128), new THREE.MeshBasicMaterial({ color: 0x2a3a48, transparent: true, opacity: 0.7 }));
        ring.rotation.x = -Math.PI / 2;
        ring.position.y = 0.01;
        scene.add(ring);
    }

    const tableMat = new THREE.MeshStandardMaterial({ color: 0x1c2a36, emissive: 0x3fc1b6, emissiveIntensity: 0.05, roughness: 0.4, metalness: 0.6 });
    const table = new THREE.Mesh(new THREE.CylinderGeometry(2.6, 2.8, 0.5, 64), tableMat);
    table.position.y = 0.25;
    table.castShadow = true;
    scene.add(table);
    const tableLabel = new CSS2DObject(Object.assign(document.createElement('div'), { className: 'team-label', textContent: 'Everyone' }));
    tableLabel.position.set(0, 0.9, 0);
    table.add(tableLabel);

    // ── One pod per agent, in team sectors around the ring ──────────────────
    const teamKeys = Object.keys(data.teams);
    const byTeam = teamKeys.map((t) => data.agents.filter((a) => a.team === t));
    const total = data.agents.length;
    const pods = {};
    let slot = 0;

    byTeam.forEach((agents, teamIndex) => {
        const team = teamKeys[teamIndex];
        const colour = new THREE.Color(TEAM_COLOURS[team] ?? 0x9fb3c8);
        const start = slot;
        agents.forEach((agent) => {
            const angle = (slot / total) * Math.PI * 2 + Math.PI / total;
            const x = Math.sin(angle) * 9;
            const z = Math.cos(angle) * 9;
            slot++;

            const group = new THREE.Group();
            group.position.set(x, 0, z);
            scene.add(group);

            const column = new THREE.Mesh(
                new THREE.CylinderGeometry(0.55, 0.65, 1, 6),
                // Tinted with the team colour so the spending height reads against the floor.
                new THREE.MeshStandardMaterial({ color: new THREE.Color(0x1d2b38).lerp(colour, 0.35), emissive: colour, emissiveIntensity: 0.08, roughness: 0.5, metalness: 0.35 }),
            );
            column.castShadow = true;
            column.receiveShadow = true;
            group.add(column);

            const ringMat = new THREE.MeshBasicMaterial({ color: colour, transparent: true, opacity: 0.6 });
            const ring = new THREE.Mesh(new THREE.TorusGeometry(0.75, 0.04, 8, 48), ringMat);
            ring.rotation.x = Math.PI / 2;
            group.add(ring);

            const orbMat = new THREE.MeshStandardMaterial({ color: colour, emissive: colour, emissiveIntensity: 0.35, roughness: 0.25, metalness: 0.2 });
            const orb = new THREE.Mesh(new THREE.IcosahedronGeometry(agent.manager ? 0.55 : 0.45, 1), orbMat);
            orb.castShadow = true;
            orb.userData = { agent };
            group.add(orb);

            const beamMat = new THREE.MeshBasicMaterial({ color: WORK, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false });
            const beam = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.35, 8, 24, 1, true), beamMat);
            group.add(beam);

            const labelEl = document.createElement('div');
            labelEl.className = 'pod-label';
            labelEl.innerHTML = `<span class="n">${esc(agent.name)}</span><span class="s"></span>`;
            const label = new CSS2DObject(labelEl);
            group.add(label);

            const orbits = new THREE.Group();
            group.add(orbits);

            pods[agent.key] = { agent, group, column, ring, orb, orbMat, beam, beamMat, label, labelEl, orbits, colour, height: 1, state: 'idle', step: '', questions: 0, phase: Math.random() * 6 };
        });

        // The team's name, in the middle of its sector, near the edge.
        if (agents.length) {
            const mid = ((start + slot) / 2 / total) * Math.PI * 2;
            const t = new CSS2DObject(Object.assign(document.createElement('div'), { className: 'team-label', textContent: data.teams[team] }));
            t.position.set(Math.sin(mid) * 13.2, 0.2, Math.cos(mid) * 13.2);
            scene.add(t);
        }
    });

    const podTop = (p) => p.height + 0.9;
    const placePod = (p) => {
        p.column.scale.y = p.height;
        p.column.position.y = p.height / 2;
        p.ring.position.y = p.height + 0.02;
        p.orb.position.y = podTop(p);
        p.beam.position.y = podTop(p) + 4;
        p.label.position.set(0, podTop(p) + 1.1, 0);
        p.orbits.position.y = podTop(p);
    };
    Object.values(pods).forEach(placePod);

    // ── Lights travelling between two pods while one answers the other ──────
    const arcs = new Map();
    const arcFor = (from, to) => {
        const a = pods[from], b = pods[to];
        const start = new THREE.Vector3().copy(a.group.position).setY(podTop(a));
        const end = new THREE.Vector3().copy(b.group.position).setY(podTop(b));
        const mid = start.clone().lerp(end, 0.5).setY(Math.max(podTop(a), podTop(b)) + 4.5);
        const curve = new THREE.QuadraticBezierCurve3(start, mid, end);
        const line = new THREE.Mesh(new THREE.TubeGeometry(curve, 48, 0.03, 6, false), new THREE.MeshBasicMaterial({ color: 0x8fe3ff, transparent: true, opacity: 0.45, blending: THREE.AdditiveBlending, depthWrite: false }));
        const spark = new THREE.Mesh(new THREE.SphereGeometry(0.16, 16, 16), new THREE.MeshBasicMaterial({ color: 0xffffff }));
        scene.add(line, spark);
        return { curve, line, spark, t: 0 };
    };
    const syncArcs = (pairs) => {
        const wanted = new Set(pairs.map(([f, t]) => `${f}>${t}`));
        for (const [key, arc] of arcs) {
            if (!wanted.has(key)) { scene.remove(arc.line, arc.spark); arc.line.geometry.dispose(); arcs.delete(key); }
        }
        for (const [f, t] of pairs) {
            const key = `${f}>${t}`;
            if (!arcs.has(key) && pods[f] && pods[t]) arcs.set(key, arcFor(f, t));
        }
    };

    // ── Amber orbs: questions waiting for the owner ─────────────────────────
    const orbGeo = new THREE.SphereGeometry(0.11, 12, 12);
    const orbMat = new THREE.MeshStandardMaterial({ color: 0xffb547, emissive: 0xffb547, emissiveIntensity: 0.9 });
    const setQuestions = (p, n) => {
        if (p.questions === n) return;
        p.questions = n;
        p.orbits.clear();
        for (let i = 0; i < Math.min(n, 8); i++) {
            const m = new THREE.Mesh(orbGeo, orbMat);
            m.userData = { question: true, i, n: Math.min(n, 8) };
            p.orbits.add(m);
        }
    };

    // ── Live state from the office ──────────────────────────────────────────
    const applyState = (state) => {
        const runs = state.runs || [];
        const scn = state.scene || {};
        const spending = scn.spending || {};
        const max = Math.max(0.01, ...Object.values(spending));

        for (const p of Object.values(pods)) {
            const run = runs.filter((r) => r.agent === p.agent.key).at(-1);
            p.state = !run ? 'idle' : run.stuck ? 'stuck' : run.waiting ? 'waiting' : 'working';
            p.step = run && !run.waiting ? (run.stuck ? `Stuck on: ${run.step || 'working'}` : `${run.step || 'Working'}…`) : run && run.waiting ? 'Waiting for its turn' : '';
            p.labelEl.querySelector('.s').textContent = p.step;
            const h = 0.4 + ((spending[p.agent.key] || 0) / max) * 4.2;
            if (Math.abs(h - p.height) > 0.01) { p.height = h; placePod(p); }
            setQuestions(p, (scn.questions || {})[p.agent.key] || 0);
        }

        syncArcs(runs.filter((r) => r.asked_by && !r.waiting).map((r) => [r.asked_by, r.agent]));
        meeting = runs.some((r) => r.meeting);
        if (typeof scn.hour === 'number') setHour(scn.hour);

        const active = runs.filter((r) => !r.waiting);
        const waiting = runs.length - active.length;
        document.getElementById('scene-now').innerHTML = runs.length
            ? '<span class="now-l live">Right now</span>' + (waiting ? `<span class="now-i">${waiting} waiting for their turn</span>` : '')
                + active.map((r) => `<span class="now-i ${r.stuck ? 'stuck' : ''}"><b>${esc(r.name)}</b> ${esc(r.step || 'Working')}</span>`).join('')
            : '<span class="now-l">Right now</span><span>Nobody is working.</span>';
    };

    let meeting = false;
    const poll = async () => {
        try {
            const res = await fetch(`${data.stateUrl}?scene=1`, { headers: { Accept: 'application/json' } });
            if (res.ok) applyState(await res.json());
        } catch (e) { /* next poll tries again */ }
        setTimeout(poll, document.hidden ? 10000 : 2000);
    };
    poll();

    // ── Clicks: a pod opens its chat, an amber orb opens the questions ──────
    const raycaster = new THREE.Raycaster();
    const pointer = new THREE.Vector2();
    let downAt = null;
    renderer.domElement.addEventListener('pointerdown', (e) => { downAt = [e.clientX, e.clientY]; });
    renderer.domElement.addEventListener('pointerup', (e) => {
        if (!downAt || Math.hypot(e.clientX - downAt[0], e.clientY - downAt[1]) > 6) return; // a drag, not a click
        const rect = renderer.domElement.getBoundingClientRect();
        pointer.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
        raycaster.setFromCamera(pointer, camera);
        const targets = Object.values(pods).flatMap((p) => [p.orb, ...p.orbits.children]);
        const hit = raycaster.intersectObjects(targets)[0];
        if (!hit) return;
        if (hit.object.userData.question) window.location.href = data.questionsUrl;
        else if (hit.object.userData.agent) window.location.href = hit.object.userData.agent.chat;
    });
    renderer.domElement.addEventListener('pointermove', (e) => {
        const rect = renderer.domElement.getBoundingClientRect();
        pointer.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
        raycaster.setFromCamera(pointer, camera);
        const over = raycaster.intersectObjects(Object.values(pods).flatMap((p) => [p.orb, ...p.orbits.children]))[0];
        renderer.domElement.style.cursor = over ? 'pointer' : 'grab';
    });

    // ── Size and animation ──────────────────────────────────────────────────
    const resize = () => {
        const { width, height } = holder.getBoundingClientRect();
        renderer.setSize(width, height);
        labels.setSize(width, height);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
    };
    new ResizeObserver(resize).observe(holder);
    resize();

    const clock = new THREE.Clock();
    const tick = () => {
        const t = clock.getElapsedTime();
        for (const p of Object.values(pods)) {
            const working = p.state === 'working';
            const colour = working ? WORK : p.state === 'stuck' ? STUCK : p.state === 'waiting' ? WAIT : p.colour;
            p.orbMat.color.lerp(colour, 0.08);
            p.orbMat.emissive.lerp(colour, 0.08);
            const breathe = still ? 0.4 : 0.3 + Math.sin(t * 1.4 + p.phase) * 0.12;
            p.orbMat.emissiveIntensity += ((working ? (still ? 1.4 : 1.2 + Math.sin(t * 6) * 0.3) : p.state === 'stuck' ? 0.9 : breathe) - p.orbMat.emissiveIntensity) * 0.1;
            p.beamMat.opacity += ((working ? 0.32 : 0) - p.beamMat.opacity) * 0.08;
            if (!still) {
                p.orb.rotation.y += working ? 0.04 : 0.005;
                p.orb.position.y = podTop(p) + (working ? Math.sin(t * 3 + p.phase) * 0.12 : Math.sin(t * 0.8 + p.phase) * 0.05);
            }
            p.orbits.children.forEach((m) => {
                const a = (m.userData.i / m.userData.n) * Math.PI * 2 + (still ? 0 : t * 0.6);
                m.position.set(Math.cos(a) * 0.95, 0.45 + Math.sin(a * 2) * 0.08, Math.sin(a) * 0.95);
            });
        }
        for (const arc of arcs.values()) {
            arc.t = (arc.t + (still ? 0 : 0.012)) % 1;
            arc.spark.position.copy(arc.curve.getPoint(still ? 0.5 : arc.t));
        }
        tableMat.emissiveIntensity += ((meeting ? (still ? 0.8 : 0.6 + Math.sin(t * 2) * 0.25) : 0.05) - tableMat.emissiveIntensity) * 0.06;
        controls.update();
        renderer.render(scene, camera);
        labels.render(scene, camera);
        requestAnimationFrame(tick);
    };
    tick();
}

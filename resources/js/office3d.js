/*
 * The 3D office: a floating office floor above a night skyline by the sea. Each team has
 * its own glass room, each agent is a character at its own desk, and a glass meeting room
 * sits in the middle. Everything that moves is real work:
 *   - a character types and its monitor scrolls only while the agent is working, and the
 *     screen shows its real current step; idle agents lean back before a dim logo;
 *   - a paper plane flies from one desk to another only while one agent is answering the
 *     other; the meeting room glows only during a team meeting;
 *   - envelopes float over an agent for each question it is waiting on you to answer;
 *   - the gold coins on a desk stack as high as the agent's share of this month's spending;
 *   - the wall screen shows who is doing what right now; light follows Bahrain time.
 * Built only from shapes in the browser: no outside models or images.
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
    const hex = (n) => '#' + n.toString(16).padStart(6, '0');

    const TEAM = {
        front: { colour: 0x3fc1b6, room: [-5, 5, 8, 14] },
        accounts: { colour: 0x7fb4ff, room: [-21, -8, 2, 14] },
        sections: { colour: 0xffc46b, room: [-21, -8, -14, -2] },
        it: { colour: 0xb69cff, room: [-5, 5, -14, -8] },
        marketing: { colour: 0xff8fb1, room: [8, 21, -1, 14] },
        planning: { colour: 0x8fe38b, room: [8, 21, -14, -4] },
    };
    const MEETING = [-6, 6, -5, 5];

    // ── Renderer, camera, controls ──────────────────────────────────────────
    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    holder.appendChild(renderer.domElement);

    const labels = new CSS2DRenderer();
    Object.assign(labels.domElement.style, { position: 'absolute', inset: '0', pointerEvents: 'none' });
    holder.appendChild(labels.domElement);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(40, 1, 0.1, 600);
    const HOME = { pos: new THREE.Vector3(0, 34, 42), target: new THREE.Vector3(0, -1, 1) };
    camera.position.copy(HOME.pos);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.minDistance = 6;
    controls.maxDistance = 80;
    controls.maxPolarAngle = Math.PI * 0.46;
    controls.target.copy(HOME.target);
    controls.autoRotate = !still;
    controls.autoRotateSpeed = 0.25;

    // ── Sky, sea, city: by Bahrain time ─────────────────────────────────────
    const hemi = new THREE.HemisphereLight(0xcfe8ff, 0x1a222a, 0.6);
    const sun = new THREE.DirectionalLight(0xffffff, 1.4);
    sun.castShadow = true;
    sun.shadow.mapSize.set(2048, 2048);
    Object.assign(sun.shadow.camera, { left: -30, right: 30, top: 30, bottom: -30, near: 1, far: 120 });
    // The office's own lights: on at night, like a real office, while the city stays dark.
    const officeLight = new THREE.AmbientLight(0xfff1dc, 0.55);
    const ceiling = new THREE.DirectionalLight(0xfff6ea, 0.6);
    ceiling.position.set(-10, 30, 18);
    scene.add(hemi, sun, officeLight, ceiling);

    const sea = new THREE.Mesh(new THREE.CircleGeometry(400, 64), new THREE.MeshStandardMaterial({ color: 0x0e3a4a, roughness: 0.35, metalness: 0.4 }));
    sea.rotation.x = -Math.PI / 2;
    sea.position.y = -14;
    scene.add(sea);

    // City towers around the bay, their windows a shared lit texture.
    const windowsCanvas = document.createElement('canvas');
    windowsCanvas.width = 64; windowsCanvas.height = 128;
    const wctx = windowsCanvas.getContext('2d');
    wctx.fillStyle = '#000'; wctx.fillRect(0, 0, 64, 128);
    for (let y = 4; y < 128; y += 8) for (let x = 4; x < 64; x += 8) {
        wctx.fillStyle = Math.random() < 0.55 ? `rgba(255, ${200 + Math.random() * 40 | 0}, 140, ${0.6 + Math.random() * 0.4})` : '#000';
        wctx.fillRect(x, y, 4, 5);
    }
    const windowsTex = new THREE.CanvasTexture(windowsCanvas);
    windowsTex.wrapS = windowsTex.wrapT = THREE.RepeatWrapping;
    const towerMat = new THREE.MeshStandardMaterial({ color: 0x2a3644, roughness: 0.6, metalness: 0.4, emissive: 0xffffff, emissiveMap: windowsTex, emissiveIntensity: 1 });
    const city = new THREE.Group();
    for (let i = 0; i < 140; i++) {
        const angle = Math.random() * Math.PI * 2;
        const dist = 90 + Math.random() * 120;
        const h = 10 + Math.random() * (dist < 130 ? 55 : 30);
        const w = 4 + Math.random() * 6;
        const tower = new THREE.Mesh(new THREE.BoxGeometry(w, h, w), towerMat);
        tower.position.set(Math.cos(angle) * dist, -14 + h / 2, Math.sin(angle) * dist);
        city.add(tower);
    }
    scene.add(city);

    const stars = new THREE.Points(
        new THREE.BufferGeometry().setAttribute('position', new THREE.Float32BufferAttribute(Array.from({ length: 900 }, (_, i) => {
            const a = Math.random() * Math.PI * 2, e = Math.random() * 0.45 + 0.08, r = 380;
            return [Math.cos(a) * Math.cos(e) * r, Math.sin(e) * r, Math.sin(a) * Math.cos(e) * r][i % 3];
        }), 3)),
        new THREE.PointsMaterial({ color: 0xffffff, size: 1.3, transparent: true, opacity: 0.8 }),
    );
    scene.add(stars);

    const setHour = (hour) => {
        const day = hour >= 6 && hour < 18;
        const dusk = hour === 6 || hour === 17;
        scene.background = new THREE.Color(day ? (dusk ? 0xf0a878 : 0x9fd2ee) : 0x070d16);
        scene.fog = new THREE.Fog(scene.background, 120, 360);
        sun.intensity = day ? 1.6 : 0.35;
        sun.color.set(day ? (dusk ? 0xffc59a : 0xfff4e6) : 0x8fa8ff);
        const a = ((hour - 6) / 12) * Math.PI;
        sun.position.set(Math.cos(a) * 40, Math.max(8, Math.sin(a) * 45), 25);
        hemi.intensity = day ? 0.75 : 0.45;
        officeLight.intensity = day ? 0.45 : 0.85;
        ceiling.intensity = day ? 0.35 : 0.9;
        sea.material.color.set(day ? 0x2a8fb0 : 0x0b2633);
        towerMat.emissiveIntensity = day ? 0.08 : 1.1;
        towerMat.color.set(day ? 0x8fa3b5 : 0x1e2834);
        stars.visible = !day;
    };
    setHour(new Date().getHours());

    // ── The floating floor, the rooms, the meeting room ─────────────────────
    const slab = new THREE.Mesh(new THREE.BoxGeometry(48, 1.2, 34), new THREE.MeshStandardMaterial({ color: 0xeef1f4, roughness: 0.85 }));
    slab.position.y = -0.6;
    slab.receiveShadow = true;
    scene.add(slab);
    const underside = new THREE.Mesh(new THREE.CylinderGeometry(6, 1.5, 12, 6), new THREE.MeshStandardMaterial({ color: 0x9aa6b2, roughness: 0.6, metalness: 0.3 }));
    underside.position.y = -7.2;
    scene.add(underside);

    const glassMat = (tint) => new THREE.MeshPhysicalMaterial({ color: tint, transparent: true, opacity: 0.16, roughness: 0.05, metalness: 0, transmission: 0.2, depthWrite: false });
    const room = ([x1, x2, z1, z2], colour, name) => {
        const group = new THREE.Group();
        const w = x2 - x1, d = z2 - z1, cx = (x1 + x2) / 2, cz = (z1 + z2) / 2;
        const carpet = new THREE.Mesh(new THREE.PlaneGeometry(w, d), new THREE.MeshStandardMaterial({ color: new THREE.Color(0xf4f6f8).lerp(new THREE.Color(colour), 0.32), roughness: 0.95 }));
        carpet.rotation.x = -Math.PI / 2;
        carpet.position.set(cx, 0.01, cz);
        carpet.receiveShadow = true;
        group.add(carpet);
        const mat = glassMat(colour);
        const frame = new THREE.MeshStandardMaterial({ color: 0x55606b, metalness: 0.7, roughness: 0.3 });
        for (const [px, pz, len, rot] of [[cx, z1, w, 0], [x1, cz, d, Math.PI / 2], [x2, cz, d, Math.PI / 2]]) {
            const wall = new THREE.Mesh(new THREE.BoxGeometry(len, 2.2, 0.06), mat);
            wall.position.set(px, 1.1, pz); wall.rotation.y = rot;
            const top = new THREE.Mesh(new THREE.BoxGeometry(len, 0.06, 0.1), frame);
            top.position.set(px, 2.2, pz); top.rotation.y = rot;
            group.add(wall, top);
        }
        if (name) {
            const sign = new CSS2DObject(Object.assign(document.createElement('div'), { className: 'room-sign', innerHTML: esc(name) }));
            sign.element.style.setProperty('--c', hex(colour));
            sign.position.set(cx, 2.7, z1);
            group.add(sign);
        }
        scene.add(group);
    };
    for (const [team, info] of Object.entries(TEAM)) room(info.room, info.colour, data.teams[team]);

    // The glass meeting room, its long table and one chair per agent.
    room(MEETING, 0x3fc1b6, 'Everyone · meeting room');
    const tableMat = new THREE.MeshStandardMaterial({ color: 0x2b3a46, emissive: 0x3fc1b6, emissiveIntensity: 0, roughness: 0.25, metalness: 0.6 });
    const table = new THREE.Mesh(new THREE.BoxGeometry(8, 0.15, 3), tableMat);
    table.position.y = 0.8; table.castShadow = true; table.receiveShadow = true;
    scene.add(table);
    const leg = new THREE.Mesh(new THREE.BoxGeometry(6, 0.75, 0.4), new THREE.MeshStandardMaterial({ color: 0x55606b, metalness: 0.6 }));
    leg.position.y = 0.4;
    scene.add(leg);
    const meetingGlow = new THREE.PointLight(0x3fc1b6, 0, 16, 1.2);
    meetingGlow.position.set(0, 3, 0);
    scene.add(meetingGlow);
    const chairMat = new THREE.MeshStandardMaterial({ color: 0x3a4652, roughness: 0.7 });
    for (let i = 0; i < data.agents.length; i++) {
        const side = i % 2 ? 1 : -1;
        const along = -3.4 + Math.floor(i / 2) * (6.8 / Math.max(1, Math.ceil(data.agents.length / 2) - 1));
        const seat = new THREE.Mesh(new THREE.BoxGeometry(0.6, 0.1, 0.6), chairMat);
        seat.position.set(along, 0.5, side * 2.1);
        scene.add(seat);
    }

    // The wall screen: who is doing what, right now.
    const wallCanvas = document.createElement('canvas');
    wallCanvas.width = 1024; wallCanvas.height = 384;
    const wallTex = new THREE.CanvasTexture(wallCanvas);
    wallTex.colorSpace = THREE.SRGBColorSpace;
    const wall = new THREE.Mesh(new THREE.PlaneGeometry(16, 6), new THREE.MeshBasicMaterial({ map: wallTex }));
    wall.position.set(0, 4.2, -16.2);
    const wallFrame = new THREE.Mesh(new THREE.BoxGeometry(16.6, 6.6, 0.3), new THREE.MeshStandardMaterial({ color: 0x1b232b, metalness: 0.6 }));
    wallFrame.position.set(0, 4.2, -16.4);
    const wallStand = new THREE.Mesh(new THREE.BoxGeometry(0.5, 1.2, 0.5), new THREE.MeshStandardMaterial({ color: 0x1b232b }));
    wallStand.position.set(0, 0.6, -16.4);
    scene.add(wallFrame, wall, wallStand);

    // ── One desk and one character per agent ────────────────────────────────
    const desks = {};
    const deskTop = new THREE.MeshStandardMaterial({ color: 0xf4f1ec, roughness: 0.6 });
    const metal = new THREE.MeshStandardMaterial({ color: 0x6b7680, metalness: 0.7, roughness: 0.35 });
    const dark = new THREE.MeshStandardMaterial({ color: 0x1b232b, roughness: 0.5 });
    const skin = [0xc68642, 0xe0ac69, 0x8d5524, 0xf1c27d, 0xa1665e];
    const coinMat = new THREE.MeshStandardMaterial({ color: 0xf5c542, metalness: 0.9, roughness: 0.25, emissive: 0x6b4f00, emissiveIntensity: 0.25 });
    const envelopeMat = new THREE.MeshStandardMaterial({ color: 0xffd36b, emissive: 0xffb547, emissiveIntensity: 0.6, side: THREE.DoubleSide });

    const drawScreen = (d, frame = 0) => {
        const c = d.screenCtx, W = 512, H = 300;
        const team = hex(d.colour);
        if (d.state === 'stuck') {
            c.fillStyle = '#3a1d08'; c.fillRect(0, 0, W, H);
            c.fillStyle = '#ffb36b'; c.font = 'bold 30px system-ui'; c.fillText('⚠ Stuck', 24, 60);
        } else if (d.state === 'working') {
            c.fillStyle = '#071318'; c.fillRect(0, 0, W, H);
            c.fillStyle = team; c.fillRect(0, 0, W, 8);
            // Lines being typed: the real step on top, a stream of work beneath.
            for (let i = 0; i < 7; i++) {
                const y = 150 + i * 20 - (frame * 3 % 20);
                const len = 80 + ((i * 97 + frame * 13 + d.seed) % 330);
                c.fillStyle = i % 3 === 0 ? team : 'rgba(160, 240, 230, 0.55)';
                c.fillRect(24, y, len, 7);
            }
            if (frame % 2 === 0) { c.fillStyle = '#9ff7ee'; c.fillRect(24 + ((frame * 21) % 400), 290, 12, 6); }
        } else if (d.state === 'waiting') {
            c.fillStyle = '#121a22'; c.fillRect(0, 0, W, H);
        } else {
            c.fillStyle = '#0c1218'; c.fillRect(0, 0, W, H);
            c.fillStyle = 'rgba(255,255,255,0.08)'; c.font = 'bold 120px system-ui'; c.textAlign = 'center'; c.fillText('W', W / 2, 190); c.textAlign = 'left';
        }
        c.fillStyle = d.state === 'idle' ? 'rgba(220,235,245,0.6)' : '#eaf6f9';
        c.font = 'bold 28px system-ui';
        c.fillText(d.agent.name, 24, d.state === 'stuck' ? 105 : 50);
        c.font = '22px system-ui';
        c.fillStyle = d.state === 'stuck' ? '#ffd3a8' : d.state === 'working' ? '#9ff7ee' : 'rgba(200,215,225,0.7)';
        const text = d.state === 'idle' ? 'Ready' : d.step;
        const words = text.split(' ');
        let line = '', y = d.state === 'stuck' ? 145 : 90;
        for (const w of words) {
            if (c.measureText(line + w).width > W - 48) { c.fillText(line, 24, y); line = ''; y += 28; if (y > 140 && d.state === 'working') break; }
            line += w + ' ';
        }
        c.fillText(line, 24, y);
        d.screenTex.needsUpdate = true;
    };

    // Desks placed in a grid inside their team's room, facing the camera.
    let seed = 1;
    for (const [team, info] of Object.entries(TEAM)) {
        const agents = data.agents.filter((a) => a.team === team);
        const [x1, x2, z1, z2] = info.room;
        const cols = Math.min(agents.length, Math.max(1, Math.floor((x2 - x1) / 4.2)));
        const rows = Math.ceil(agents.length / cols);
        agents.forEach((agent, i) => {
            const col = i % cols, row = Math.floor(i / cols);
            const x = x1 + ((col + 0.5) * (x2 - x1)) / cols;
            const z = z1 + ((row + 0.5) * (z2 - z1)) / Math.max(rows, 1) - 0.4;
            const colour = new THREE.Color(info.colour);
            const g = new THREE.Group();
            g.position.set(x, 0, z);
            g.scale.setScalar(1.3);
            scene.add(g);

            const top = new THREE.Mesh(new THREE.BoxGeometry(2.4, 0.08, 1.2), deskTop);
            top.position.y = 0.78; top.castShadow = true; top.receiveShadow = true;
            g.add(top);
            for (const [lx, lz] of [[-1.1, -0.5], [1.1, -0.5], [-1.1, 0.5], [1.1, 0.5]]) {
                const l = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.78, 0.06), metal);
                l.position.set(lx, 0.39, lz); g.add(l);
            }

            // Monitor facing the camera and the character.
            const stand = new THREE.Mesh(new THREE.BoxGeometry(0.1, 0.4, 0.1), metal);
            stand.position.set(0, 1.0, -0.35); g.add(stand);
            const bezel = new THREE.Mesh(new THREE.BoxGeometry(1.5, 0.92, 0.06), dark);
            bezel.position.set(0, 1.55, -0.38); bezel.castShadow = true; g.add(bezel);
            const screenCanvas = document.createElement('canvas');
            screenCanvas.width = 512; screenCanvas.height = 300;
            const screenTex = new THREE.CanvasTexture(screenCanvas);
            screenTex.colorSpace = THREE.SRGBColorSpace;
            const screen = new THREE.Mesh(new THREE.PlaneGeometry(1.4, 0.82), new THREE.MeshBasicMaterial({ map: screenTex }));
            screen.position.set(0, 1.55, -0.345); g.add(screen);
            const keyboard = new THREE.Mesh(new THREE.BoxGeometry(0.8, 0.03, 0.25), dark);
            keyboard.position.set(0, 0.84, 0.15); g.add(keyboard);

            // Chair and character, sitting with its back to the camera.
            const seat = new THREE.Mesh(new THREE.BoxGeometry(0.7, 0.1, 0.7), chairMat);
            seat.position.set(0, 0.5, 0.95); g.add(seat);
            const back = new THREE.Mesh(new THREE.BoxGeometry(0.7, 0.8, 0.08), chairMat);
            back.position.set(0, 0.95, 1.3); g.add(back);

            const body = new THREE.Group();
            body.position.set(0, 0.55, 0.95);
            g.add(body);
            const shirt = new THREE.MeshStandardMaterial({ color: colour, roughness: 0.7 });
            const torso = new THREE.Mesh(new THREE.CapsuleGeometry(0.24, 0.45, 6, 12), shirt);
            torso.position.y = 0.48; torso.castShadow = true; body.add(torso);
            const head = new THREE.Mesh(new THREE.SphereGeometry(0.2, 20, 16), new THREE.MeshStandardMaterial({ color: skin[(seed * 7) % skin.length], roughness: 0.8 }));
            head.position.y = 1.03; head.castShadow = true; body.add(head);
            const hair = new THREE.Mesh(new THREE.SphereGeometry(0.205, 20, 16, 0, Math.PI * 2, 0, Math.PI / 2), new THREE.MeshStandardMaterial({ color: 0x1f1a17, roughness: 0.9 }));
            hair.position.y = 1.06; body.add(hair);
            const arms = [];
            for (const side of [-1, 1]) {
                const shoulder = new THREE.Group();
                shoulder.position.set(side * 0.3, 0.75, 0);
                const arm = new THREE.Mesh(new THREE.CapsuleGeometry(0.07, 0.42, 4, 8), shirt);
                arm.position.set(0, -0.12, -0.2); arm.rotation.x = Math.PI / 2.4;
                shoulder.add(arm);
                body.add(shoulder);
                arms.push(shoulder);
            }

            // Gold coins: this month's spending. Envelopes: questions waiting.
            const coins = new THREE.Group();
            coins.position.set(0.85, 0.82, 0.1);
            g.add(coins);
            const envelopes = new THREE.Group();
            envelopes.position.set(0, 2.6, 0.3);
            g.add(envelopes);

            const tag = new CSS2DObject(Object.assign(document.createElement('div'), { className: 'desk-tag' }));
            tag.element.innerHTML = `<b>${esc(agent.name)}</b>${agent.name !== agent.title ? `<span>${esc(agent.title)}</span>` : ''}`;
            tag.element.style.setProperty('--c', hex(info.colour));
            tag.position.set(0, 2.15, 0.95);
            g.add(tag);

            // The beam that rises while it is this agent's turn in a meeting.
            const beamMat = new THREE.MeshBasicMaterial({ color: 0x9ff7ee, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false });
            const beam = new THREE.Mesh(new THREE.CylinderGeometry(0.25, 0.6, 9, 24, 1, true), beamMat);
            beam.position.set(0, 6.5, 0.95); g.add(beam);

            const hit = new THREE.Mesh(new THREE.BoxGeometry(2.8, 2.8, 2.8), new THREE.MeshBasicMaterial({ transparent: true, opacity: 0, depthWrite: false }));
            hit.position.set(0, 1.2, 0.4);
            hit.userData = { agent };
            g.add(hit);

            const d = { agent, g, colour, screenCtx: screenCanvas.getContext('2d'), screenTex, body, torso, head, arms, coins, envelopes, beamMat, hit, state: 'idle', step: '', meeting: false, questions: -1, coinsShown: -1, seed: seed++ * 37, light: null };
            const lamp = new THREE.PointLight(0x9ff7ee, 0, 6, 1.2);
            lamp.position.set(0, 1.6, 0.2); g.add(lamp);
            d.light = lamp;
            drawScreen(d);
            desks[agent.key] = d;
        });
    }

    const setCoins = (d, n) => {
        if (d.coinsShown === n) return;
        d.coinsShown = n;
        d.coins.clear();
        for (let i = 0; i < n; i++) {
            const coin = new THREE.Mesh(new THREE.CylinderGeometry(0.11, 0.11, 0.035, 20), coinMat);
            coin.position.y = i * 0.04;
            coin.rotation.y = i * 0.6;
            d.coins.add(coin);
        }
    };
    const setEnvelopes = (d, n) => {
        if (d.questions === n) return;
        d.questions = n;
        d.envelopes.clear();
        for (let i = 0; i < Math.min(n, 6); i++) {
            const env = new THREE.Mesh(new THREE.PlaneGeometry(0.42, 0.28), envelopeMat);
            env.userData = { question: true, i, n: Math.min(n, 6) };
            d.envelopes.add(env);
        }
    };

    // ── Paper planes: one agent asking another ──────────────────────────────
    const planeGeo = new THREE.BufferGeometry().setAttribute('position', new THREE.Float32BufferAttribute([0, 0, 0.35, -0.22, 0, -0.2, 0, 0.06, -0.12, 0, 0, 0.35, 0, 0.06, -0.12, 0.22, 0, -0.2], 3));
    planeGeo.computeVertexNormals();
    const planeMat = new THREE.MeshStandardMaterial({ color: 0xffffff, emissive: 0x8fe3ff, emissiveIntensity: 0.8, side: THREE.DoubleSide });
    const planes = new Map();
    const syncPlanes = (pairs) => {
        const wanted = new Set(pairs.map(([f, t]) => `${f}>${t}`));
        for (const [key, p] of planes) if (!wanted.has(key)) { scene.remove(p.mesh, p.trail); planes.delete(key); }
        for (const [f, t] of pairs) {
            const key = `${f}>${t}`;
            if (planes.has(key) || !desks[f] || !desks[t]) continue;
            const a = desks[f].g.position.clone().setY(2.2), b = desks[t].g.position.clone().setY(2.2);
            const curve = new THREE.QuadraticBezierCurve3(a, a.clone().lerp(b, 0.5).setY(7 + a.distanceTo(b) * 0.12), b);
            const trail = new THREE.Mesh(new THREE.TubeGeometry(curve, 60, 0.025, 6, false), new THREE.MeshBasicMaterial({ color: 0x8fe3ff, transparent: true, opacity: 0.35, blending: THREE.AdditiveBlending, depthWrite: false }));
            const mesh = new THREE.Mesh(planeGeo, planeMat);
            scene.add(trail, mesh);
            planes.set(key, { curve, mesh, trail, t: 0 });
        }
    };

    // ── The wall screen ─────────────────────────────────────────────────────
    const drawWall = (runs, hour) => {
        const c = wallCanvas.getContext('2d');
        c.fillStyle = '#071017'; c.fillRect(0, 0, 1024, 384);
        c.fillStyle = '#3fc1b6'; c.font = 'bold 40px system-ui'; c.fillText('WANAAN OFFICE · LIVE', 40, 62);
        c.fillStyle = 'rgba(220,235,245,0.6)'; c.font = '24px system-ui';
        c.fillText(`Bahrain ${String(hour).padStart(2, '0')}:${String(new Date().getMinutes()).padStart(2, '0')}`, 830, 60);
        const active = runs.filter((r) => !r.waiting);
        const waiting = runs.length - active.length;
        c.font = '34px system-ui';
        if (!runs.length) { c.fillStyle = 'rgba(220,235,245,0.8)'; c.fillText('Everyone is ready. Nobody is working right now.', 40, 160); }
        active.slice(0, 5).forEach((r, i) => {
            c.fillStyle = r.stuck ? '#ffb36b' : '#ffffff'; c.font = 'bold 32px system-ui';
            c.fillText(r.name, 40, 130 + i * 48);
            c.fillStyle = r.stuck ? '#ffd3a8' : '#9ff7ee'; c.font = '30px system-ui';
            c.fillText((r.step || 'Working').slice(0, 44), 360, 130 + i * 48);
        });
        if (waiting) { c.fillStyle = 'rgba(220,235,245,0.6)'; c.fillText(`${waiting} waiting for their turn`, 40, 360); }
        wallTex.needsUpdate = true;
    };

    // ── Live state ──────────────────────────────────────────────────────────
    let meeting = false;
    let hourNow = new Date().getHours();
    const applyState = (state) => {
        const runs = state.runs || [];
        const scn = state.scene || {};
        const spending = scn.spending || {};
        const max = Math.max(0.01, ...Object.values(spending));
        for (const d of Object.values(desks)) {
            const run = runs.filter((r) => r.agent === d.agent.key).at(-1);
            const next = !run ? 'idle' : run.stuck ? 'stuck' : run.waiting ? 'waiting' : 'working';
            const step = run ? (run.stuck ? `Stuck on: ${run.step || 'working'}` : run.waiting ? 'Waiting for my turn' : `${run.step || 'Working'}…`) : '';
            const changed = next !== d.state || step !== d.step;
            d.state = next; d.step = step; d.meeting = !!(run && run.meeting && !run.waiting);
            if (changed) drawScreen(d);
            setCoins(d, spending[d.agent.key] ? 1 + Math.round((spending[d.agent.key] / max) * 11) : 0);
            setEnvelopes(d, (scn.questions || {})[d.agent.key] || 0);
        }
        syncPlanes(runs.filter((r) => r.asked_by && !r.waiting).map((r) => [r.asked_by, r.agent]));
        meeting = runs.some((r) => r.meeting);
        if (typeof scn.hour === 'number') { hourNow = scn.hour; setHour(scn.hour); }
        drawWall(runs, hourNow);

        const active = runs.filter((r) => !r.waiting);
        const waiting = runs.length - active.length;
        document.getElementById('scene-now').innerHTML = runs.length
            ? '<span class="now-l live">Right now</span>' + (waiting ? `<span class="now-i">${waiting} waiting for their turn</span>` : '')
                + active.map((r) => `<span class="now-i ${r.stuck ? 'stuck' : ''}"><b>${esc(r.name)}</b> ${esc(r.step || 'Working')}</span>`).join('')
            : '<span class="now-l">Right now</span><span>Nobody is working.</span>';
    };

    const poll = async () => {
        try {
            const res = await fetch(`${data.stateUrl}?scene=1`, { headers: { Accept: 'application/json' } });
            if (res.ok) applyState(await res.json());
        } catch (e) { /* next poll tries again */ }
        setTimeout(poll, document.hidden ? 10000 : 2000);
    };
    drawWall([], hourNow);
    poll();

    // ── Choosing a desk: the camera glides to it ────────────────────────────
    let flight = null;
    const card = document.getElementById('scene-card');
    const flyTo = (pos, target) => { flight = { fromPos: camera.position.clone(), fromTarget: controls.target.clone(), pos, target, t: 0 }; controls.autoRotate = false; };
    const choose = (agent) => {
        const d = desks[agent.key];
        const at = d.g.position.clone();
        flyTo(at.clone().add(new THREE.Vector3(3, 5, 7.5)), at.clone().add(new THREE.Vector3(0, 1.6, 0)));
        card.hidden = false;
        card.querySelector('[data-name]').textContent = agent.name;
        card.querySelector('[data-title]').textContent = agent.name !== agent.title ? agent.title : '';
        card.querySelector('[data-step]').textContent = d.state === 'idle' ? 'Ready' : d.step;
        card.querySelector('[data-chat]').href = agent.chat;
        card.querySelector('[data-chat]').textContent = `Talk to ${agent.name}`;
    };
    document.getElementById('scene-home').addEventListener('click', () => { card.hidden = true; flyTo(HOME.pos.clone(), HOME.target.clone()); setTimeout(() => { controls.autoRotate = !still; }, 1600); });

    const raycaster = new THREE.Raycaster();
    const pointer = new THREE.Vector2();
    const pick = (e) => {
        const rect = renderer.domElement.getBoundingClientRect();
        pointer.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
        raycaster.setFromCamera(pointer, camera);
        return raycaster.intersectObjects(Object.values(desks).flatMap((d) => [...d.envelopes.children, d.hit]))[0];
    };
    let downAt = null;
    renderer.domElement.addEventListener('pointerdown', (e) => { downAt = [e.clientX, e.clientY]; });
    renderer.domElement.addEventListener('pointerup', (e) => {
        if (!downAt || Math.hypot(e.clientX - downAt[0], e.clientY - downAt[1]) > 6) return;
        const hit = pick(e);
        if (!hit) return;
        if (hit.object.userData.question) window.location.href = data.questionsUrl;
        else if (hit.object.userData.agent) choose(hit.object.userData.agent);
    });
    renderer.domElement.addEventListener('pointermove', (e) => { renderer.domElement.style.cursor = pick(e) ? 'pointer' : 'grab'; });

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
    let frame = 0, lastScreen = 0;
    const tick = () => {
        const t = clock.getElapsedTime();
        frame++;

        if (flight) {
            flight.t = Math.min(1, flight.t + 0.018);
            const k = flight.t < 0.5 ? 2 * flight.t * flight.t : 1 - Math.pow(-2 * flight.t + 2, 2) / 2;
            camera.position.lerpVectors(flight.fromPos, flight.pos, k);
            controls.target.lerpVectors(flight.fromTarget, flight.target, k);
            if (flight.t >= 1) flight = null;
        }

        const redrawScreens = !still && t - lastScreen > 0.15;
        if (redrawScreens) lastScreen = t;
        for (const d of Object.values(desks)) {
            const working = d.state === 'working';
            // Lean in to type while working; lean back when idle.
            const lean = working ? 0.16 : d.state === 'idle' ? -0.12 : 0;
            d.body.rotation.x += (lean - d.body.rotation.x) * 0.08;
            d.arms.forEach((arm, i) => {
                arm.rotation.x = working && !still ? Math.sin(t * 14 + i * Math.PI) * 0.12 : arm.rotation.x * 0.9;
            });
            if (!still) d.head.position.y = 1.03 + (working ? Math.sin(t * 4 + d.seed) * 0.015 : Math.sin(t * 0.8 + d.seed) * 0.008);
            d.light.intensity += ((working ? 9 : d.state === 'stuck' ? 7 : 0) - d.light.intensity) * 0.1;
            d.light.color.set(d.state === 'stuck' ? 0xffa060 : 0x9ff7ee);
            d.beamMat.opacity += ((d.meeting ? 0.28 : 0) - d.beamMat.opacity) * 0.08;
            if (working && redrawScreens) drawScreen(d, frame);
            d.envelopes.children.forEach((env) => {
                const a = (env.userData.i / env.userData.n) * Math.PI * 2 + (still ? 0 : t * 0.7);
                env.position.set(Math.cos(a) * 0.7, Math.sin(t * 2 + env.userData.i) * (still ? 0 : 0.08), Math.sin(a) * 0.7);
                env.rotation.y = -a;
            });
        }
        for (const p of planes.values()) {
            p.t = (p.t + (still ? 0 : 0.006)) % 1;
            const at = p.curve.getPoint(still ? 0.5 : p.t);
            p.mesh.position.copy(at);
            p.mesh.lookAt(p.curve.getPoint(Math.min(0.999, (still ? 0.5 : p.t) + 0.01)));
        }
        tableMat.emissiveIntensity += ((meeting ? (still ? 0.7 : 0.5 + Math.sin(t * 2) * 0.2) : 0) - tableMat.emissiveIntensity) * 0.06;
        meetingGlow.intensity += ((meeting ? 14 : 0) - meetingGlow.intensity) * 0.06;

        controls.update();
        renderer.render(scene, camera);
        labels.render(scene, camera);
        requestAnimationFrame(tick);
    };
    tick();
}

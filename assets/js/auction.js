document.addEventListener('DOMContentLoaded', () => {
    const bidModal = document.getElementById('bid-modal');
    const bidForm = document.getElementById('bid-form');
    const toastContainer = document.getElementById('toast-container');
    const activityList = document.getElementById('activity-list');

    // SVG Generation Helpers
    const hash = (s) => { 
        let h = 2166136261; 
        for (let i=0; i<s.length; i++){ h ^= s.charCodeAt(i); h = (h*16777619) >>> 0; } 
        return h; 
    };

    const generateCover = (id, title, genre) => {
        const h = hash(id + title);
        const hue1 = h % 360; 
        const hue2 = (h * 7) % 360; 
        const angle = (h % 180) - 90;
        const sc = 4 + (h % 4);
        
        let stripesHtml = '';
        for(let i=0; i<sc; i++) {
            const off = (i/sc)*100;
            const w = (100/sc) * 0.45;
            stripesHtml += `<rect x="${off}" y="0" width="${w}" height="100" fill="oklch(0.40 0.10 ${hue1 + i*20})" opacity="0.35" />`;
        }

        return `
            <svg class="stripes" viewBox="0 0 100 100" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="g-${id}" gradientTransform="rotate(${angle})">
                        <stop offset="0%" stop-color="oklch(0.30 0.08 ${hue1})" />
                        <stop offset="100%" stop-color="oklch(0.22 0.06 ${hue2})" />
                    </linearGradient>
                </defs>
                <rect width="100" height="100" fill="url(#g-${id})" />
                ${stripesHtml}
                <text x="8" y="94" fill="oklch(0.95 0.01 85)" opacity="0.9" font-size="7" font-family="JetBrains Mono, monospace" letter-spacing="0.5">${genre.toUpperCase()}</text>
            </svg>
        `;
    };

    const generateWaveform = (seed) => {
        const h = hash(seed);
        let barsHtml = '';
        for(let i=0; i<32; i++) {
            const v = Math.sin((h + i*13)*0.013)*0.5 + 0.5;
            const height = (0.2 + v*0.8) * 28 + 4;
            barsHtml += `<div class="bar" style="height: ${height}px"></div>`;
        }
        return barsHtml;
    };

    // Feedback Sounds
    const playFeedbackSound = (type = 'click') => {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = type === 'error' ? 'sawtooth' : 'sine';
            osc.frequency.setValueAtTime(type === 'bid' ? 880 : 440, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.1);
            gain.gain.setValueAtTime(0.1, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.1);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.1);
        } catch(e) {}
    };

    const refreshCaptcha = () => {
        const a = Math.floor(Math.random() * 10) + 1;
        const b = Math.floor(Math.random() * 10) + 1;
        const label = document.getElementById('captcha-label');
        if (label) {
            label.innerText = `Security: ${a} + ${b} = ?`;
            bidForm.dataset.ans = a + b;
        }
    };

    const parseBeatData = (raw) => {
        if (!raw) return null;
        try {
            return JSON.parse(raw);
        } catch (err) {
            try {
                const decoded = raw.replace(/&quot;/g, '"').replace(/&amp;/g, '&');
                return JSON.parse(decoded);
            } catch (err2) {
                console.error('Failed to parse beat data:', err, err2, raw);
                return null;
            }
        }
    };

    const openBidModal = (beat) => {
        if (!beat || !bidModal) return;
        playFeedbackSound('click');
        const modalId = document.getElementById('modal-beat-id');
        const modalAmt = document.getElementById('modal-amount');
        const titleEl = document.getElementById('modal-beat-title');

        if (modalId) modalId.value = beat.id;
        if (modalAmt) {
            modalAmt.min = (parseFloat(beat.current_bid) || parseFloat(beat.starting_bid)) + (beat.top_bidder ? 5 : 0);
            modalAmt.value = modalAmt.min;
        }
        if (titleEl) titleEl.innerText = beat.title;

        refreshCaptcha();
        bidModal.classList.add('is-visible');
    };

    let currentAudio = null;
    let audioTimer = null;

    const stopAudio = () => {
        if (currentAudio) {
            currentAudio.pause();
            currentAudio = null;
        }
        if (audioTimer) {
            clearTimeout(audioTimer);
            audioTimer = null;
        }
        document.querySelectorAll('.card').forEach(c => c.classList.remove('is-playing'));
        document.querySelectorAll('.play svg').forEach(s => s.innerHTML = '<path d="M8 5v14l11-7z"/>');
    };

    // Initialize UI
    document.querySelectorAll('.card').forEach(card => {
        const id = card.getAttribute('data-id');
        const title = card.querySelector('.title').innerText;
        const genre = card.querySelector('.producer').innerText.split(' · ').pop();
        const coverContainer = card.querySelector('.cover');
        const existingSvg = coverContainer.querySelector('.stripes');
        if (existingSvg) existingSvg.outerHTML = generateCover(id, title, genre);
        const waveContainer = card.querySelector('.wave');
        waveContainer.innerHTML = generateWaveform(id);
    });

    // Direct place-bid button binding for reliable modal opening
    document.querySelectorAll('.open-bid').forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const beat = parseBeatData(button.dataset.beat || button.getAttribute('data-beat'));
            if (beat) {
                openBidModal(beat);
            } else {
                console.error('Invalid beat payload for bid button.', button);
            }
        });
    });

    // Event Delegation for Modal & Audio
    document.addEventListener('click', (e) => {
        const openBtn = e.target.closest('.open-bid');
        const lbItem = e.target.closest('.lb-item');
        const trigger = openBtn || lbItem;

        if (trigger) {
            let beat = null;
            if (openBtn) {
                beat = parseBeatData(openBtn.dataset.beat || openBtn.getAttribute('data-beat'));
            } else if (lbItem) {
                const beatId = lbItem.getAttribute('data-beat-id');
                const mainBtn = document.querySelector(`.card[data-id="${beatId}"] .open-bid`);
                if (mainBtn) beat = JSON.parse(mainBtn.getAttribute('data-beat'));
            }

            if (beat) {
                openBidModal(beat);
            }
        }

        // Close Modal
        if (e.target === bidModal || e.target.id === 'close-modal') {
            bidModal.classList.remove('is-visible');
        }

        // Audio Logic
        const playBtn = e.target.closest('.play');
        if (playBtn) {
            const card = playBtn.closest('.card');
            const sampleUrl = playBtn.getAttribute('data-sample');

            if (card.classList.contains('is-playing')) {
                stopAudio();
                return;
            }

            stopAudio();
            playFeedbackSound('click');
            playBtn.innerHTML = '<span class="loading-spinner"></span>'; 

            currentAudio = new Audio(sampleUrl);
            currentAudio.play().then(() => {
                card.classList.add('is-playing');
                playBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
            }).catch(err => {
                console.error("Audio error", err);
                showToast('Playback failed. Check cloud storage.', 'error');
                stopAudio();
            });

            audioTimer = setTimeout(stopAudio, 40000); 
            currentAudio.onended = stopAudio;
        }
    });

    // Timer Logic
    const updateTimers = () => {
        document.querySelectorAll('.timer').forEach(el => {
            let ends = el.getAttribute('data-ends');
            if (!ends) {
                el.innerText = "Waiting";
                el.classList.remove('danger');
                return;
            }
            
            // Parse the timestamp - could be unix seconds or datetime string
            let endsTs;
            if (!isNaN(ends) && ends.length > 5) {
                // Unix timestamp in seconds
                endsTs = parseInt(ends) * 1000;
            } else {
                // Datetime string like "2025-05-16 14:30:45"
                endsTs = new Date(ends).getTime();
            }
            
            if (isNaN(endsTs)) {
                el.innerText = "Waiting";
                el.classList.remove('danger');
                return;
            }
            
            const diff = Math.max(0, Math.floor((endsTs - Date.now()) / 1000));
            if (diff <= 0) {
                el.innerText = "CLOSED";
                el.classList.add('danger');
                return;
            }
            const m = Math.floor(diff / 60);
            const s = diff % 60;
            el.innerText = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
            if (diff < 300) el.classList.add('danger');
            else el.classList.remove('danger');
        });
    };
    setInterval(updateTimers, 1000);
    updateTimers();

    // Bid Submission
    bidForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const ans = bidForm.elements['captcha_ans']?.value;
        if (ans != bidForm.dataset.ans) {
            playFeedbackSound('error');
            showToast("Security answer incorrect.", "error");
            refreshCaptcha();
            return;
        }

        const formData = new FormData(bidForm);
        const submitBtn = bidForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerText = "Placing...";
        
        try {
            const res = await fetch('/api/bid.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                playFeedbackSound('bid');
                showToast(`Bid placed successfully!`, 'ok');
                
                // Update timer and beat data immediately for the bidding user
                const beatId = bidForm.elements['beat_id'].value;
                const card = document.querySelector(`.card[data-id="${beatId}"]`);
                if (card && data.ends_at) {
                    const timerEl = card.querySelector('.timer');
                    if (timerEl) {
                        // Convert ends_at (unix timestamp in seconds) to string for data attribute
                        const endTimestamp = typeof data.ends_at === 'string' ? data.ends_at : String(data.ends_at);
                        timerEl.setAttribute('data-ends', endTimestamp);
                    }
                    
                    // Also update the beat data on the button for next time
                    const btn = card.querySelector('.open-bid');
                    if (btn) {
                        const beat = JSON.parse(btn.getAttribute('data-beat'));
                        beat.ends_at = data.ends_at;
                        btn.setAttribute('data-beat', JSON.stringify(beat));
                    }
                    
                    // Immediately update timer display
                    updateTimers();
                }
                
                bidModal.classList.remove('is-visible');
                bidForm.reset();
            } else {
                playFeedbackSound('error');
                showToast(data.error || 'Failed to place bid', 'error');
                refreshCaptcha();
            }
        } catch (err) {
            showToast('Network error', 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerText = "Place Bid →";
        }
    });

    // SSE Integration (using extensionless URL)
    let evtSource = null;
    const startSSE = () => {
        if (evtSource) evtSource.close();
        evtSource = new EventSource('api/updates.php');
        
        evtSource.addEventListener('activity', (e) => {
            const act = JSON.parse(e.data);
            const item = document.createElement('div');
            item.className = 'activity-item';
            item.innerHTML = `<span class="dot"></span><span><b>${act.user_handle}</b> ${act.message}</span>`;
            if (activityList) {
                activityList.prepend(item);
                if (activityList.children.length > 8) activityList.lastChild.remove();
            }
            if (act.type === 'bid') {
                playFeedbackSound('bid');
                showToast(`${act.user_handle} bid $${act.amount} on a beat!`, 'ok');
                const card = document.querySelector(`.card[data-id="${act.beat_id}"]`);
                if (card) {
                    const priceEl = card.querySelector('.v.accent');
                    if (priceEl) priceEl.innerText = `$${parseFloat(act.current_bid).toFixed(2)}`;
                    
                    const timerEl = card.querySelector('.timer');
                    if (timerEl) timerEl.setAttribute('data-ends', act.ends_at);
                    
                    const stats = card.querySelector('.bidstats');
                    if (stats) stats.innerHTML = `top <b>${act.user_handle}</b>`;

                    // Update data-beat for the button
                    const btn = card.querySelector('.open-bid');
                    if (btn) {
                        const beat = JSON.parse(btn.getAttribute('data-beat'));
                        beat.current_bid = act.current_bid;
                        beat.top_bidder = act.user_handle;
                        beat.ends_at = act.ends_at;
                        btn.setAttribute('data-beat', JSON.stringify(beat));
                    }
                    
                    const lbItem = document.querySelector(`.lb-item[data-beat-id="${act.beat_id}"]`);
                    if (lbItem) {
                        lbItem.querySelector('.amt').innerText = `$${parseFloat(act.current_bid).toLocaleString()}`;
                        lbItem.classList.add('is-bumped');
                        setTimeout(() => lbItem.classList.remove('is-bumped'), 1000);
                    }
                }
            }
        });

        evtSource.onerror = () => {
            console.warn("SSE Connection lost. Retrying in 10s...");
            evtSource.close();
            setTimeout(startSSE, 10000); // Wait 10s before retrying to avoid server spam
        };
    };
    startSSE();

    // Toast Helper
    function showToast(msg, kind) {
        const toast = document.createElement('div');
        toast.className = 'toast show';
        toast.innerHTML = `<span class="tdot" style="background:${kind==='ok'?'var(--ok)':'var(--danger)'}"></span> ${msg}`;
        if (toastContainer) toastContainer.appendChild(toast);
        else document.body.appendChild(toast);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400);
        }, 4000);
    }
});

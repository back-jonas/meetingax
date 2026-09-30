(function () {
    const root = document.body;
    if (root.dataset.poll !== "admin" || !root.dataset.meeting) {
        bindStatic();
        return;
    }

    bindStatic();
    let signature = root.dataset.signature || "";
    let stopped = false;

    async function tick() {
        if (stopped) {
            return;
        }
        try {
            const response = await fetch("/api/admin/meeting/state?meeting=" + encodeURIComponent(root.dataset.meeting), {
                headers: { "Accept": "application/json", "X-Requested-With": "fetch" }
            });
            if (response.status === 401) {
                stopped = true;
                window.location.href = "/login";
                return;
            }
            if (!response.ok) {
                return;
            }
            const json = await response.json();
            if (!json.success) {
                return;
            }
            applyState(json.data);
            if (signature && json.data.signature !== signature) {
                signature = json.data.signature;
                root.dataset.signature = signature;
                await refreshFragments();
            } else {
                signature = json.data.signature;
            }
        } catch (error) {
            // Nästa poll får försöka igen. Ett nätverksfel ska inte tömma sidan.
        }
    }

    function applyState(data) {
        setText("[data-meeting-status]", data.meeting_status_label);
        Object.keys(data.counts).forEach(function (key) {
            setText('[data-count="' + CSS.escape(key) + '"]', String(data.counts[key]));
        });
        data.polls.forEach(function (poll) {
            setText('[data-poll-count="' + CSS.escape(poll.public_id) + '"]', String(poll.submitted_count));
        });
        data.presence.forEach(function (person) {
            setText('[data-presence="' + CSS.escape(person.public_id) + '"]', person.label);
        });
    }

    async function refreshFragments() {
        const blocks = document.querySelectorAll("[data-fragment]");
        for (const block of blocks) {
            if (block.contains(document.activeElement)) {
                continue;
            }
            const response = await fetch(window.location.pathname + "?fragment=" + encodeURIComponent(block.dataset.fragment), {
                headers: { "Accept": "text/html", "X-Requested-With": "fetch" }
            });
            if (response.status === 401) {
                stopped = true;
                window.location.href = "/login";
                return;
            }
            if (!response.ok) {
                continue;
            }
            block.innerHTML = await response.text();
        }
    }

    function setText(selector, value) {
        document.querySelectorAll(selector).forEach(function (element) {
            element.textContent = value;
        });
    }

    function bindStatic() {
        const type = document.querySelector("[data-field-type]");
        const options = document.querySelector("[data-field-options]");
        if (type && options) {
            const sync = function () {
                options.hidden = type.value !== "select";
            };
            type.addEventListener("change", sync);
            sync();
        }
        const votingType = document.querySelector("[data-voting-type]");
        const customOptions = document.querySelector("[data-custom-options]");
        if (votingType && customOptions) {
            const syncVoting = function () {
                customOptions.hidden = votingType.value !== "single_choice";
            };
            votingType.addEventListener("change", syncVoting);
            syncVoting();
        }
        document.addEventListener("click", function (event) {
            const button = event.target.closest("[data-copy]");
            if (!button) {
                return;
            }
            const target = document.querySelector(button.getAttribute("data-copy"));
            if (!target || !navigator.clipboard) {
                return;
            }
            navigator.clipboard.writeText(target.textContent.trim()).then(function () {
                button.textContent = "Kopierad";
            }).catch(function () {});
        });
    }

    window.setInterval(tick, 2000);
}());

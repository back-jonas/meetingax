(function () {
    const root = document.body;
    if (root.dataset.poll !== "participant") {
        return;
    }

    let signature = root.dataset.signature || "";
    let stopped = false;

    async function tick() {
        if (stopped) {
            return;
        }
        try {
            const response = await fetch("/api/participant/state", {
                headers: { "Accept": "application/json", "X-Requested-With": "fetch" }
            });
            if (response.status === 401) {
                stopped = true;
                const live = document.querySelector("[data-live]");
                if (live) {
                    live.textContent = "Din åtkomst till mötet har avslutats.";
                }
                return;
            }
            if (!response.ok) {
                return;
            }
            const json = await response.json();
            if (!json.success) {
                return;
            }
            const next = json.data.signature;
            if (signature && next !== signature) {
                window.location.reload();
                return;
            }
            signature = next;
        } catch (error) {
            // Pollingen fortsätter vid tillfälliga fel.
        }
    }

    window.setInterval(tick, 2000);
}());

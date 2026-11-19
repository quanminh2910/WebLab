const availabilityForm = document.querySelector("#availability-form");

if (availabilityForm) {
    const status = document.querySelector("#availability-status");
    const times = document.querySelector("#available-times");
    let activeRequest;

    async function loadAvailability() {
        activeRequest?.abort();
        times.replaceChildren();
        if (!availabilityForm.checkValidity()) {
            status.textContent = "Choose a specialist and a valid date.";
            return;
        }

        activeRequest = new AbortController();
        const { signal } = activeRequest;
        status.textContent = "Checking available times…";
        const url = new URL(availabilityForm.action);
        url.search = new URLSearchParams(new FormData(availabilityForm));
        try {
            const response = await fetch(url, { signal });
            const data = await response.json();
            if (signal.aborted) return;
            if (!response.ok) {
                status.textContent = data.error || "Select Check times to try again.";
                return;
            }
            for (const time of data.times) {
                const item = document.createElement("li");
                item.textContent = time;
                times.append(item);
            }
            status.textContent = data.times.length
                ? "Available start times in Ho Chi Minh City"
                : "No start times are available for this specialist on this date.";
        } catch (error) {
            if (!signal.aborted) {
                status.textContent = "Could not load availability. Select Check times to try again.";
            }
        }
    }

    availabilityForm.addEventListener("change", loadAvailability);
    availabilityForm.addEventListener("submit", event => {
        event.preventDefault();
        loadAvailability();
    });
    availabilityForm.hidden = false;
    loadAvailability();
}

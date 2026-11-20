const availabilityForm = document.querySelector("#availability-form");

if (availabilityForm) {
    const status = document.querySelector("#availability-status");
    const times = document.querySelector("#available-times");
    const staff = availabilityForm.elements.staff_id;
    const date = availabilityForm.elements.date;
    const confirmBooking = document.querySelector("#confirm-booking");
    let activeRequest;

    async function loadAvailability() {
        activeRequest?.abort();
        times.replaceChildren();
        if (confirmBooking) confirmBooking.disabled = true;
        if (!date.validity.valid) {
            date.setAttribute("aria-invalid", "true");
            status.textContent = date.validity.rangeUnderflow
                ? "Choose today or a future date."
                : "Enter a valid appointment date.";
            return;
        }
        date.removeAttribute("aria-invalid");
        if (!staff.validity.valid) {
            status.textContent = "Choose a specialist to see available times.";
            return;
        }

        activeRequest = new AbortController();
        const { signal } = activeRequest;
        status.textContent = "Checking available times…";
        const url = new URL(availabilityForm.dataset.timesUrl, document.baseURI);
        url.search = new URLSearchParams({
            service_id: availabilityForm.elements.service_id.value,
            staff_id: staff.value,
            date: date.value
        });
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
                if (confirmBooking) {
                    const radio = document.createElement("input");
                    radio.type = "radio";
                    radio.name = "start_time";
                    radio.value = time;
                    radio.required = true;
                    const label = document.createElement("label");
                    label.append(radio, time);
                    item.append(label);
                } else {
                    item.textContent = time;
                }
                times.append(item);
            }
            status.textContent = data.times.length
                ? "Available start times in Ho Chi Minh City"
                : "No start times are available for this specialist on this date. Try another date or specialist.";
        } catch (error) {
            if (!signal.aborted) {
                status.textContent = "Could not load availability. Select Check times to try again.";
            }
        }
    }

    staff.addEventListener("change", loadAvailability);
    date.addEventListener("change", loadAvailability);
    document.querySelector("#check-times").addEventListener("click", loadAvailability);
    if (confirmBooking) {
        times.addEventListener("change", () => { confirmBooking.disabled = false; });
    } else {
        availabilityForm.addEventListener("submit", event => {
            event.preventDefault();
            loadAvailability();
        });
    }
    window.addEventListener("pageshow", event => {
        if (event.persisted) loadAvailability();
    });
    availabilityForm.hidden = false;
    loadAvailability();
}

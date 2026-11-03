function showMessage() {
    alert("Your spa service has been added to your booking request.");
}

function getStoredValue(key) {
    try {
        return window.localStorage.getItem(key);
    } catch (error) {
        return null;
    }
}

function setStoredValue(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch (error) {
        // The interaction still works when browser storage is unavailable.
    }
}

function getThemeCookie() {
    try {
        const prefix = "spa_theme=";
        const cookie = document.cookie.split(";").map(value => value.trim())
            .find(value => value.startsWith(prefix));
        const theme = cookie ? decodeURIComponent(cookie.slice(prefix.length)) : null;
        return theme === "dark" || theme === "light" ? theme : null;
    } catch (error) {
        return null;
    }
}

function rememberTheme(theme) {
    if (theme !== "dark" && theme !== "light") {
        return false;
    }
    try {
        const maxAge = 30 * 24 * 60 * 60;
        const expires = new Date(Date.now() + maxAge * 1000).toUTCString();
        const secure = window.location.protocol === "https:" ? "; Secure" : "";
        document.cookie = `spa_theme=${theme}; Max-Age=${maxAge}; Expires=${expires}; Path=/; SameSite=Lax${secure}`;
        return getThemeCookie() === theme;
    } catch (error) {
        // The toggle still works for this page when browser cookies are blocked.
        return false;
    }
}

document.addEventListener("DOMContentLoaded", function () {
    const themeToggle = document.querySelector("#theme-toggle");

    if (!themeToggle) {
        return;
    }

    const themeIcon = document.querySelector("#theme-icon");
    const themeLabel = document.querySelector("#theme-label");
    const cookieTheme = getThemeCookie();
    const legacyTheme = getStoredValue("spa-theme");
    const validLegacyTheme = legacyTheme === "dark" || legacyTheme === "light"
        ? legacyTheme : null;
    const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
    let currentTheme = cookieTheme || validLegacyTheme || (prefersDark ? "dark" : "light");

    // Migrate the old preference once; a valid cookie is always authoritative.
    if (validLegacyTheme && (cookieTheme || rememberTheme(validLegacyTheme))) {
        try {
            window.localStorage.removeItem("spa-theme");
        } catch (error) {
            // Favorites and the selected theme work even when storage is unavailable.
        }
    }

    function applyTheme(theme) {
        const isDark = theme === "dark";

        document.documentElement.dataset.theme = theme;
        themeToggle.setAttribute("aria-pressed", String(isDark));
        themeToggle.setAttribute("aria-label", isDark
            ? "Switch to light mode"
            : "Switch to dark mode");
        themeIcon.textContent = isDark ? "☀" : "☾";
        themeLabel.textContent = isDark ? "Light mode" : "Dark mode";
    }

    applyTheme(currentTheme);

    themeToggle.addEventListener("click", function () {
        currentTheme = currentTheme === "dark" ? "light" : "dark";
        applyTheme(currentTheme);
        rememberTheme(currentTheme);
    });
});

document.addEventListener("DOMContentLoaded", function () {
    const searchForm = document.querySelector("#service-search");

    if (!searchForm) {
        return;
    }

    const searchInput = document.querySelector("#service-search-input");
    const categoryButtons = document.querySelectorAll(".category-filter");
    const serviceCards = Array.from(document.querySelectorAll(".service-card"));
    const searchStatus = document.querySelector("#search-status");
    const favoriteButtons = document.querySelectorAll(".favorite-button");
    const favoriteStatus = document.querySelector("#favorite-status");
    let favoriteMessageTimer;
    let selectedCategory = "all";
    let favoriteServices = new Set();

    try {
        const storedFavorites = JSON.parse(getStoredValue("spa-favorites") || "[]");

        if (Array.isArray(storedFavorites)) {
            favoriteServices = new Set(storedFavorites);
        }
    } catch (error) {
        favoriteServices = new Set();
    }

    function filterServices() {
        const query = searchInput.value.trim().toLocaleLowerCase();
        let visibleCount = 0;

        serviceCards.forEach(function (card) {
            const matchesSearch = card.textContent.toLocaleLowerCase().includes(query);
            const matchesCategory = selectedCategory === "all"
                || card.dataset.category === selectedCategory;
            const shouldShow = matchesSearch && matchesCategory;

            card.hidden = !shouldShow;

            if (shouldShow) {
                visibleCount += 1;
            }
        });

        if (visibleCount === 0) {
            searchStatus.textContent = "No services match your search.";
        } else if (visibleCount === 1) {
            searchStatus.textContent = "Showing 1 service.";
        } else {
            searchStatus.textContent = `Showing ${visibleCount} services.`;
        }
    }

    searchForm.addEventListener("submit", function (event) {
        event.preventDefault();
        filterServices();
    });

    searchInput.addEventListener("input", filterServices);

    categoryButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            selectedCategory = button.dataset.category;

            categoryButtons.forEach(function (categoryButton) {
                const isSelected = categoryButton === button;
                categoryButton.classList.toggle("active", isSelected);
                categoryButton.setAttribute("aria-pressed", String(isSelected));
            });

            filterServices();
        });
    });

    function updateFavoriteButton(button, isFavorite) {
        const serviceName = button.dataset.service;
        const icon = button.querySelector("span");

        button.classList.toggle("is-favorite", isFavorite);
        button.setAttribute("aria-pressed", String(isFavorite));
        button.setAttribute("aria-label", isFavorite
            ? `Remove ${serviceName} from favorites`
            : `Add ${serviceName} to favorites`);
        icon.textContent = isFavorite ? "♥" : "♡";
    }

    favoriteButtons.forEach(function (button) {
        const serviceName = button.dataset.service;

        updateFavoriteButton(button, favoriteServices.has(serviceName));

        button.addEventListener("click", function () {
            const isFavorite = !favoriteServices.has(serviceName);

            if (isFavorite) {
                favoriteServices.add(serviceName);
            } else {
                favoriteServices.delete(serviceName);
            }

            updateFavoriteButton(button, isFavorite);
            setStoredValue("spa-favorites", JSON.stringify(Array.from(favoriteServices)));

            favoriteStatus.textContent = isFavorite
                ? `${serviceName} added to favorites.`
                : `${serviceName} removed from favorites.`;
            favoriteStatus.hidden = false;

            window.clearTimeout(favoriteMessageTimer);
            favoriteMessageTimer = window.setTimeout(function () {
                favoriteStatus.hidden = true;
            }, 2500);
        });
    });
});

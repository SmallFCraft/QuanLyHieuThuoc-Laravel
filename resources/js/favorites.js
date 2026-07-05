const FAVORITE_STORAGE_KEY = 'pharmacy.favoriteProducts';
const profileRemovalTimers = new WeakMap();

function getConfig() {
    return window.PharmacyFavorites || {};
}

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function favoriteUrl(template, productId) {
    return String(template || '').replace('__PRODUCT_ID__', encodeURIComponent(productId));
}

function getStoredFavorites() {
    try {
        return new Set(JSON.parse(localStorage.getItem(FAVORITE_STORAGE_KEY) || '[]').map(String));
    } catch (error) {
        return new Set();
    }
}

function setStoredFavorites(favorites) {
    localStorage.setItem(FAVORITE_STORAGE_KEY, JSON.stringify([...favorites].map(String)));
}

function getFavoriteButtons() {
    return document.querySelectorAll('[data-favorite-toggle]');
}

function syncFavoriteButton(button, favorites) {
    const productId = String(button.dataset.productId || '');
    const saved = favorites.has(productId);
    const favoriteLabel = button.dataset.favoriteLabel || 'Luu yeu thich';
    const savedLabel = button.dataset.savedLabel || 'Da luu';
    const icon = button.querySelector('i');
    const text = button.querySelector('[data-favorite-text]');

    button.classList.toggle('is-active', saved);
    button.setAttribute('aria-pressed', saved ? 'true' : 'false');
    button.setAttribute('aria-label', saved ? savedLabel : favoriteLabel);
    button.setAttribute('title', saved ? savedLabel : favoriteLabel);

    icon?.classList.toggle('bi-heart-fill', saved);
    icon?.classList.toggle('bi-heart', !saved);

    if (text) {
        text.textContent = saved ? savedLabel : favoriteLabel;
    }
}

function syncAllFavoriteButtons(favorites) {
    getFavoriteButtons().forEach((button) => syncFavoriteButton(button, favorites));
}

function syncFavoriteCounters(favorites) {
    document.querySelectorAll('[data-favorites-count]').forEach((element) => {
        element.textContent = favorites.size;
    });
}

function getProfileFavoritesRoot() {
    return document.querySelector('[data-profile-favorites]');
}

function getProfileFavoriteItems(root = getProfileFavoritesRoot()) {
    return root ? [...root.querySelectorAll('[data-favorite-item]')] : [];
}

function getProfileFavoriteItemId(item) {
    return String(item?.dataset.productId || '');
}

function hideProfileFavoriteItem(item) {
    item.dataset.favoriteHidden = 'true';
    item.style.opacity = '0';
    item.style.transform = 'translateY(8px)';
    item.style.pointerEvents = 'none';
}

function showProfileFavoriteItem(item) {
    const timer = profileRemovalTimers.get(item);

    if (timer) {
        clearTimeout(timer);
        profileRemovalTimers.delete(item);
    }

    delete item.dataset.favoriteHidden;
    item.style.opacity = '';
    item.style.transform = '';
    item.style.pointerEvents = '';
}

function removeProfileFavoriteItem(item) {
    const timer = profileRemovalTimers.get(item);

    if (timer) {
        clearTimeout(timer);
        profileRemovalTimers.delete(item);
    }

    item.remove();
}

function queueProfileFavoriteRemoval(item, delay = 220) {
    if (!item || profileRemovalTimers.has(item)) {
        return;
    }

    hideProfileFavoriteItem(item);
    profileRemovalTimers.set(item, setTimeout(() => removeProfileFavoriteItem(item), delay));
}

function hasVisibleProfileFavoriteItems(root) {
    return getProfileFavoriteItems(root).some((item) => item.dataset.favoriteHidden !== 'true');
}

function scheduleProfileFavoritesRefresh(root, refreshProfileFavorites, delay = 260) {
    if (!root || root.dataset.favoriteRefreshing === 'true' || !refreshProfileFavorites) {
        return;
    }

    root.dataset.favoriteRefreshing = 'true';
    setTimeout(async () => {
        try {
            await refreshProfileFavorites();
        } finally {
            getProfileFavoritesRoot()?.removeAttribute('data-favorite-refreshing');
        }
    }, delay);
}

function refreshProfileFavoritesWhenEmpty(root, refreshProfileFavorites, delay = 260) {
    if (!root) {
        return;
    }

    setTimeout(() => {
        if (!hasVisibleProfileFavoriteItems(root)) {
            scheduleProfileFavoritesRefresh(root, refreshProfileFavorites, 0);
        }
    }, delay);
}

function syncProfileFavoriteItems(favorites, options = {}) {
    const root = getProfileFavoritesRoot();

    if (!root) {
        return;
    }

    const {
        removeStaleItems = true,
        removeDelay = 220,
        refreshOnMissingItems = false,
        refreshWhenEmpty = false,
        refreshProfileFavorites = null,
    } = options;
    const currentItemIds = new Set();

    getProfileFavoriteItems(root).forEach((item) => {
        const productId = getProfileFavoriteItemId(item);

        if (!productId) {
            return;
        }

        currentItemIds.add(productId);

        if (favorites.has(productId)) {
            showProfileFavoriteItem(item);
            return;
        }

        if (removeStaleItems) {
            queueProfileFavoriteRemoval(item, removeDelay);
        }
    });

    if (refreshOnMissingItems && [...favorites].some((productId) => !currentItemIds.has(productId))) {
        scheduleProfileFavoritesRefresh(root, refreshProfileFavorites);
        return;
    }

    if (refreshWhenEmpty) {
        refreshProfileFavoritesWhenEmpty(root, refreshProfileFavorites, removeDelay + 40);
    }
}

function syncFavoriteUi(favorites, options = {}) {
    syncAllFavoriteButtons(favorites);
    syncFavoriteCounters(favorites);
    syncProfileFavoriteItems(favorites, options.profile || {});
}

function applyFavoriteState(favoritesRef, favorites, options = {}) {
    favoritesRef.current = favorites;

    if (options.persist !== false) {
        setStoredFavorites(favorites);
    }

    syncFavoriteUi(favorites, options);
    return favorites;
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
            ...(options.headers || {}),
        },
        ...options,
    });

    if (!response.ok) {
        throw new Error(`Favorite request failed: ${response.status}`);
    }

    return response.json();
}

async function refreshProfileFavoritesSection(favoritesRef) {
    const config = getConfig();
    const root = getProfileFavoritesRoot();

    if (!root || !config.authenticated || !config.sectionUrl) {
        return false;
    }

    root.setAttribute('aria-busy', 'true');

    try {
        const data = await requestJson(config.sectionUrl, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const html = String(data.html || '').trim();

        if (!html) {
            return false;
        }

        root.outerHTML = html;

        applyFavoriteState(favoritesRef, new Set((data.favorites || []).map(String)), {
            profile: {
                removeStaleItems: false,
            },
        });
        setupProfileRemoveButtons(favoritesRef);

        return true;
    } finally {
        const currentRoot = getProfileFavoritesRoot();
        currentRoot?.removeAttribute('aria-busy');
        currentRoot?.removeAttribute('data-favorite-refreshing');
    }
}

async function syncFavoritesWithServer() {
    const config = getConfig();
    const storedFavorites = getStoredFavorites();

    if (!config.authenticated || !config.syncUrl) {
        return {
            favorites: storedFavorites,
            syncedCount: 0,
        };
    }

    const data = await requestJson(config.syncUrl, {
        method: 'POST',
        body: JSON.stringify({
            product_ids: [...storedFavorites],
        }),
    });

    const favorites = new Set((data.favorites || []).map(String));

    return {
        favorites,
        syncedCount: Number(data.synced_count || 0),
    };
}

async function toggleFavorite(button, favoritesRef) {
    const config = getConfig();
    const productId = String(button.dataset.productId || '');
    const favorites = favoritesRef.current;
    const refreshProfileFavorites = () => refreshProfileFavoritesSection(favoritesRef);

    if (!productId) {
        return favorites;
    }

    const nextFavorites = new Set(favorites);
    const shouldSave = !nextFavorites.has(productId);

    if (shouldSave) {
        nextFavorites.add(productId);
    } else {
        nextFavorites.delete(productId);
    }

    applyFavoriteState(favoritesRef, nextFavorites, {
        profile: {
            refreshProfileFavorites,
            refreshWhenEmpty: true,
        },
    });

    if (!config.authenticated || !config.toggleUrlTemplate) {
        return nextFavorites;
    }

    button.disabled = true;

    try {
        const data = await requestJson(favoriteUrl(config.toggleUrlTemplate, productId), {
            method: 'POST',
            body: JSON.stringify({}),
        });
        const serverFavorites = new Set((data.favorites || []).map(String));
        return applyFavoriteState(favoritesRef, serverFavorites, {
            profile: {
                refreshProfileFavorites,
                refreshWhenEmpty: true,
            },
        });
    } catch (error) {
        applyFavoriteState(favoritesRef, favorites);
        throw error;
    } finally {
        button.disabled = false;
    }
}

function showFavoriteError() {
    if (window.Swal?.fire) {
        window.Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'error',
            title: 'Chua cap nhat duoc yeu thich',
            text: 'Vui long thu lai sau.',
            showConfirmButton: false,
            timer: 2600,
        });
    }
}

function setupProfileRemoveButtons(favoritesRef) {
    const config = getConfig();
    const profileRoot = getProfileFavoritesRoot();
    const refreshProfileFavorites = () => refreshProfileFavoritesSection(favoritesRef);

    document.querySelectorAll('[data-profile-favorite-remove]').forEach((button) => {
        if (button.dataset.favoriteRemoveBound === 'true') {
            return;
        }

        button.dataset.favoriteRemoveBound = 'true';

        button.addEventListener('click', async () => {
            const productId = String(button.dataset.productId || '');
            const item = button.closest('[data-favorite-item]');
            const favorites = favoritesRef.current;
            const nextFavorites = new Set(favorites);

            if (!productId || button.disabled) {
                return;
            }

            button.disabled = true;
            nextFavorites.delete(productId);

            if (item) {
                hideProfileFavoriteItem(item);
            }

            applyFavoriteState(favoritesRef, nextFavorites, {
                profile: {
                    removeStaleItems: false,
                    refreshProfileFavorites,
                },
            });

            if (config.authenticated && config.destroyUrlTemplate) {
                try {
                    await requestJson(favoriteUrl(config.destroyUrlTemplate, productId), {
                        method: 'DELETE',
                        body: JSON.stringify({}),
                    });

                    if (item) {
                        removeProfileFavoriteItem(item);
                    }

                    refreshProfileFavoritesWhenEmpty(profileRoot, refreshProfileFavorites);
                } catch (error) {
                    applyFavoriteState(favoritesRef, favorites, {
                        profile: {
                            removeStaleItems: false,
                            refreshProfileFavorites,
                        },
                    });

                    if (item) {
                        showProfileFavoriteItem(item);
                    }

                    showFavoriteError();
                } finally {
                    button.disabled = false;
                }
                return;
            }

            if (item) {
                removeProfileFavoriteItem(item);
            }

            refreshProfileFavoritesWhenEmpty(profileRoot, refreshProfileFavorites);
            button.disabled = false;
        });
    });
}

document.addEventListener('DOMContentLoaded', async () => {
    const favoritesRef = {
        current: getStoredFavorites(),
    };
    const refreshProfileFavorites = () => refreshProfileFavoritesSection(favoritesRef);

    syncFavoriteUi(favoritesRef.current, {
        profile: {
            removeStaleItems: false,
            refreshProfileFavorites,
        },
    });

    try {
        const syncResult = await syncFavoritesWithServer();
        applyFavoriteState(favoritesRef, syncResult.favorites, {
            profile: {
                refreshProfileFavorites,
                refreshWhenEmpty: true,
            },
        });

        if (
            syncResult.syncedCount > 0
            && document.querySelector('[data-profile-favorites]')
            && !sessionStorage.getItem('pharmacy.favoriteProfileSynced')
        ) {
            sessionStorage.setItem('pharmacy.favoriteProfileSynced', '1');
            await refreshProfileFavorites();
        }
    } catch (error) {
        syncFavoriteUi(favoritesRef.current, {
            profile: {
                removeStaleItems: false,
                refreshProfileFavorites,
            },
        });
    }

    getFavoriteButtons().forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await toggleFavorite(button, favoritesRef);
            } catch (error) {
                showFavoriteError();
            }
        });
    });

    setupProfileRemoveButtons(favoritesRef);

    window.addEventListener('storage', (event) => {
        if (event.key !== FAVORITE_STORAGE_KEY) {
            return;
        }

        const previousFavorites = favoritesRef.current;
        const nextFavorites = getStoredFavorites();
        const hasAddedFavorite = [...nextFavorites].some((productId) => !previousFavorites.has(productId));

        applyFavoriteState(favoritesRef, nextFavorites, {
            persist: false,
            profile: {
                removeDelay: 800,
                refreshProfileFavorites,
                refreshOnMissingItems: hasAddedFavorite,
                refreshWhenEmpty: true,
            },
        });
    });
});

import { ref, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Composable do globalnych skrótów klawiszowych.
 *
 * Skróty nawigacyjne (prefix "g"):
 *   g d  → Dashboard
 *   g t  → Zadania
 *   g k  → Kanban
 *   g c  → Klienci
 *   g a  → Kalendarz
 *   g u  → Użytkownicy
 *   g s  → Ustawienia
 *
 * Skróty akcji:
 *   Ctrl+K / Cmd+K → Szybkie wyszukiwanie wizyt (floating panel)
 *   /    → Focus na szukajkę
 *   n    → Nowy element (kontekstowo)
 *   ?    → Pokaż pomoc (lista skrótów)
 *   Esc  → Zamknij modal / wyczyść focus
 *
 * @param {Object} options
 * @param {Function} options.onQuickOpenVisit - callback gdy Ctrl+K / Cmd+K
 */
export function useKeyboardShortcuts(options = {}) {
    const showHelp = ref(false);
    const pendingPrefix = ref(null);
    let prefixTimeout = null;

    const shortcuts = [
        { keys: 'g → d', description: 'Przejdź do Dashboard' },
        { keys: 'g → t', description: 'Przejdź do Zadań' },
        { keys: 'g → k', description: 'Przejdź do Kanban' },
        { keys: 'g → c', description: 'Przejdź do Klientów' },
        { keys: 'g → a', description: 'Przejdź do Kalendarza' },
        { keys: 'g → u', description: 'Przejdź do Użytkowników' },
        { keys: 'g → s', description: 'Przejdź do Ustawień' },
        { keys: '⌘K / Ctrl+K', description: 'Szybkie wyszukiwanie wizyt' },
        { keys: 'Ctrl+klik na wizytę', description: 'Otwórz w osobnym panelu' },
        { keys: '/', description: 'Focus na pole wyszukiwania' },
        { keys: 'n', description: 'Nowy element (zależnie od strony)' },
        { keys: '?', description: 'Pokaż / ukryj tę pomoc' },
        { keys: 'Esc', description: 'Zamknij modal / cofnij' },
    ];

    function isInputFocused() {
        const el = document.activeElement;
        if (!el || !el.tagName) return false;
        const tag = String(el.tagName).toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
    }

    function navigateTo(routeName) {
        try {
            router.visit(route(routeName));
        } catch {
            // Route nie istnieje — ignoruj
        }
    }

    function handleKeydown(e) {
        // Nie reaguj w polach formularza (z wyjątkiem Escape)
        if (e.key === 'Escape') {
            // Zamknij pomoc
            if (showHelp.value) {
                showHelp.value = false;
                e.preventDefault();
                return;
            }
            // Odblokuj focus z inputa
            if (isInputFocused()) {
                document.activeElement.blur();
                e.preventDefault();
                return;
            }
            return;
        }

        if (isInputFocused()) return;

        // Ctrl+K / Cmd+K – szybkie wyszukiwanie wizyt
        if ((e.ctrlKey || e.metaKey) && e.key && String(e.key).toLowerCase() === 'k') {
            e.preventDefault();
            options.onQuickOpenVisit?.();
            return;
        }

        // Ignoruj gdy Ctrl/Meta/Alt (poza Ctrl+Enter w komentarzach)
        if (e.ctrlKey || e.metaKey || e.altKey) return;

        const key = e.key ? String(e.key).toLowerCase() : '';
        if (!key) return;

        // Obsługa prefixu "g"
        if (pendingPrefix.value === 'g') {
            clearTimeout(prefixTimeout);
            pendingPrefix.value = null;

            const navMap = {
                d: 'dashboard',
                t: 'tasks.index',
                k: 'kanban.index',
                c: 'clients.index',
                a: 'calendar.index',
                u: 'users.index',
                s: 'admin.settings.index',
            };

            if (navMap[key]) {
                e.preventDefault();
                navigateTo(navMap[key]);
            }
            return;
        }

        // Rozpocznij prefix "g"
        if (key === 'g') {
            pendingPrefix.value = 'g';
            prefixTimeout = setTimeout(() => {
                pendingPrefix.value = null;
            }, 800);
            return;
        }

        // Pokaż pomoc
        if (key === '?') {
            e.preventDefault();
            showHelp.value = !showHelp.value;
            return;
        }

        // Focus na szukajkę
        if (key === '/') {
            e.preventDefault();
            const searchInput = document.querySelector('input[placeholder*="Szukaj"]');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
            return;
        }

        // Nowy element — kontekstowe
        if (key === 'n') {
            e.preventDefault();
            const currentRoute = route().current();
            if (currentRoute?.startsWith('tasks.')) {
                navigateTo('tasks.create');
            } else if (currentRoute?.startsWith('clients.')) {
                navigateTo('clients.create');
            } else if (currentRoute?.startsWith('users.')) {
                navigateTo('users.create');
            }
            return;
        }
    }

    onMounted(() => {
        window.addEventListener('keydown', handleKeydown);
    });

    onUnmounted(() => {
        window.removeEventListener('keydown', handleKeydown);
        clearTimeout(prefixTimeout);
    });

    return {
        showHelp,
        shortcuts,
    };
}

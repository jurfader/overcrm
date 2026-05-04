/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{vue,js}',
        './modules/*/resources/js/**/*.{vue,js}',
        './modules/*/resources/views/**/*.blade.php',
    ],
    // dark mode sterowany atrybutem `data-theme="dark"` na <html> (ustawiany w app.blade.php)
    darkMode: ['selector', '[data-theme="dark"]'],
    theme: {
        extend: {
            colors: {
                // Brand — CSS variables, customowalne per klient
                brand: {
                    primary:   'var(--brand-primary)',
                    secondary: 'var(--brand-secondary)',
                },

                // Surface stack — adapt do dark/light
                background:    'var(--color-background)',
                'background-alt': 'var(--color-background-alt)',
                surface:       'var(--color-surface)',
                'surface-2':   'var(--color-surface-2)',
                'surface-elevated': 'var(--color-surface-elevated)',
                'surface-hover': 'var(--color-surface-hover)',

                foreground:    'var(--color-foreground)',
                'foreground-muted':  'var(--color-muted-foreground)',
                'foreground-subtle': 'var(--color-subtle)',

                border:        'var(--color-border)',
                'border-bright': 'var(--color-border-bright)',
                'border-hover':  'var(--color-border-hover)',

                // Status
                success:     'var(--color-success)',
                warning:     'var(--color-warning)',
                destructive: 'var(--color-destructive)',
                info:        'var(--color-info)',
            },
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'sans-serif'],
                mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'monospace'],
            },
            borderRadius: {
                xs:  'var(--radius-xs)',
                sm:  'var(--radius-sm)',
                DEFAULT: 'var(--radius)',
                md:  'var(--radius-md)',
                lg:  'var(--radius-lg)',
                xl:  'var(--radius-xl)',
                '2xl': 'var(--radius-2xl)',
            },
            boxShadow: {
                sm: 'var(--shadow-sm)',
                DEFAULT: 'var(--shadow)',
                lg: 'var(--shadow-lg)',
            },
            spacing: {
                'sidebar': 'var(--sidebar-width)',
                'sidebar-collapsed': 'var(--sidebar-width-collapsed)',
                'topbar': 'var(--topbar-height)',
            },
        },
    },
    plugins: [
        require('@tailwindcss/forms')({ strategy: 'class' }),
    ],
}

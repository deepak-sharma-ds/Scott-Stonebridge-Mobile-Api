import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                admin: {
                    sidebar:        '#0f172a',
                    'sidebar-hover':'#1e293b',
                    primary:        '#6366f1',
                    'primary-dark': '#4f46e5',
                    'primary-lt':   '#818cf8',
                    success:        '#10b981',
                    warning:        '#f59e0b',
                    danger:         '#ef4444',
                    info:           '#3b82f6',
                    content:        '#f1f5f9',
                    card:           '#ffffff',
                    border:         '#e2e8f0',
                },
            },
            animation: {
                'slide-up':    'slideUp 0.5s cubic-bezier(0.4,0,0.2,1) backwards',
                'fade-in':     'fadeIn 0.3s ease-out',
                'slide-right': 'slideRight 0.3s cubic-bezier(0.4,0,0.2,1)',
            },
            keyframes: {
                slideUp: {
                    '0%':   { opacity: '0', transform: 'translateY(20px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                fadeIn: {
                    '0%':   { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                slideRight: {
                    '0%':   { opacity: '0', transform: 'translateX(100%)' },
                    '100%': { opacity: '1', transform: 'translateX(0)' },
                },
            },
        },
    },

    plugins: [forms],
};

import type { SVGAttributes } from 'react';

/**
 * The Freyr mark: a stylized Fehu rune (ᚠ) — the Elder Futhark rune for
 * wealth. Shapes inherit the current fill color.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
            <rect x="16" y="8" width="6" height="32" rx="3" />
            <rect
                x="17"
                y="13"
                width="20"
                height="6"
                rx="3"
                transform="rotate(-22 17 16)"
            />
            <rect
                x="17"
                y="24"
                width="20"
                height="6"
                rx="3"
                transform="rotate(-22 17 27)"
            />
        </svg>
    );
}

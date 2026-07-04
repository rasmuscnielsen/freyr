import { Check } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

export type CategoryComboboxOption = {
    id: number;
    label: string;
};

type CategoryComboboxProps = {
    /**
     * The full, unfiltered list of selectable options. Filtering happens inside
     * the component so keyboard navigation and the visible list always agree.
     */
    options: CategoryComboboxOption[];
    /** Fired when the user picks an option (id) or clears (null). */
    onSelect: (subcategoryId: number | null) => void;
    /** Closes the popup without changing anything (Escape / Tab / click-outside). */
    onClose: () => void;
    /** When true the input is disabled and a spinner is shown (PATCH in flight). */
    isSaving?: boolean;
    /** Number of results to render at once. */
    maxResults?: number;
    /** Debounce delay (ms) applied to the filter input. */
    debounceMs?: number;
};

/**
 * Accessible category picker implementing the WAI-ARIA combobox (with listbox
 * popup) pattern. Owns its own search/active-option state so callers only deal
 * with open/close + selection.
 */
export function CategoryCombobox({
    options,
    onSelect,
    onClose,
    isSaving = false,
    maxResults = 20,
    debounceMs = 100,
}: CategoryComboboxProps) {
    const listboxId = useId();
    const optionIdPrefix = useId();

    const containerRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const listRef = useRef<HTMLDivElement>(null);

    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [rawActiveIndex, setActiveIndex] = useState(0);
    const [flipUp, setFlipUp] = useState(false);

    const filteredOptions = useMemo(() => {
        const needle = debouncedSearch.trim().toLowerCase();
        const matches =
            needle === ''
                ? options
                : options.filter((option) =>
                      option.label.toLowerCase().includes(needle),
                  );

        return matches.slice(0, maxResults);
    }, [debouncedSearch, options, maxResults]);

    // Clamp the active option to the current results at render time so the
    // highlighted row and the DOM never disagree, without a syncing effect.
    const activeIndex =
        filteredOptions.length === 0
            ? 0
            : Math.min(rawActiveIndex, filteredOptions.length - 1);

    // Debounce the search value that drives filtering. Typing stays responsive
    // because `search` (the input value) updates immediately.
    useEffect(() => {
        const timer = window.setTimeout(
            () => {
                setDebouncedSearch(search);
            },
            Math.max(debounceMs, 0),
        );

        return () => window.clearTimeout(timer);
    }, [search, debounceMs]);

    // Focus the input on open.
    useEffect(() => {
        inputRef.current?.focus();
    }, []);

    const optionId = useCallback(
        (index: number) => `${optionIdPrefix}-option-${index}`,
        [optionIdPrefix],
    );

    // Scroll the active option into view when navigating with the keyboard.
    useEffect(() => {
        const list = listRef.current;

        if (!list) {
            return;
        }

        const activeEl = list.querySelector<HTMLElement>(
            `#${CSS.escape(optionId(activeIndex))}`,
        );

        activeEl?.scrollIntoView({ block: 'nearest' });
    }, [activeIndex, optionId, filteredOptions]);

    // Decide whether to render above or below the trigger based on available
    // room, so the popup stays within the viewport near the bottom of the page.
    useLayoutEffect(() => {
        const container = containerRef.current;

        if (!container) {
            return;
        }

        const rect = container.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.top;

        // Popup can grow to roughly 22rem; flip up when there is not enough room
        // below but there is more room above.
        const estimatedHeight = 352;

        setFlipUp(spaceBelow < estimatedHeight && rect.top > spaceBelow);
    }, []);

    // Close on click / pointer outside the popup.
    useEffect(() => {
        const handlePointerDown = (event: PointerEvent) => {
            const target = event.target as Node | null;

            if (target && containerRef.current?.contains(target)) {
                return;
            }

            onClose();
        };

        document.addEventListener('pointerdown', handlePointerDown, true);

        return () =>
            document.removeEventListener(
                'pointerdown',
                handlePointerDown,
                true,
            );
    }, [onClose]);

    const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (isSaving) {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose();
            }

            return;
        }

        switch (event.key) {
            case 'Escape': {
                event.preventDefault();
                onClose();
                break;
            }
            case 'Tab': {
                // Let focus leave, but collapse the popup.
                onClose();
                break;
            }
            case 'ArrowDown': {
                event.preventDefault();

                if (filteredOptions.length === 0) {
                    break;
                }

                setActiveIndex(
                    (current) => (current + 1) % filteredOptions.length,
                );

                break;
            }
            case 'ArrowUp': {
                event.preventDefault();

                if (filteredOptions.length === 0) {
                    break;
                }

                setActiveIndex(
                    (current) =>
                        (current - 1 + filteredOptions.length) %
                        filteredOptions.length,
                );

                break;
            }
            case 'Home': {
                event.preventDefault();
                setActiveIndex(0);
                break;
            }
            case 'End': {
                event.preventDefault();

                if (filteredOptions.length > 0) {
                    setActiveIndex(filteredOptions.length - 1);
                }

                break;
            }
            case 'Enter': {
                event.preventDefault();
                const option = filteredOptions[activeIndex];

                if (option) {
                    onSelect(option.id);
                }

                break;
            }
            default:
                break;
        }
    };

    const hasResults = filteredOptions.length > 0;
    const activeDescendant = hasResults ? optionId(activeIndex) : undefined;

    return (
        <div
            ref={containerRef}
            className={cn(
                'absolute left-2 z-20 w-80 rounded-md border bg-popover p-2 shadow-lg',
                flipUp ? 'bottom-1' : 'top-1',
            )}
        >
            <div className="relative">
                <Input
                    ref={inputRef}
                    role="combobox"
                    aria-expanded={hasResults}
                    aria-controls={listboxId}
                    aria-activedescendant={activeDescendant}
                    aria-autocomplete="list"
                    aria-label="Search category"
                    autoComplete="off"
                    disabled={isSaving}
                    value={search}
                    onChange={(event) => {
                        setSearch(event.target.value);
                        setActiveIndex(0);
                    }}
                    onKeyDown={handleKeyDown}
                    placeholder="Search category"
                    className={cn(isSaving && 'pr-9')}
                />
                {isSaving && (
                    <Spinner className="pointer-events-none absolute top-2.5 right-3 text-muted-foreground" />
                )}
            </div>
            <div
                ref={listRef}
                id={listboxId}
                role="listbox"
                aria-label="Categories"
                className="mt-2 max-h-64 overflow-y-auto"
            >
                {hasResults ? (
                    filteredOptions.map((option, index) => (
                        <button
                            key={option.id}
                            id={optionId(index)}
                            type="button"
                            role="option"
                            aria-selected={index === activeIndex}
                            tabIndex={-1}
                            disabled={isSaving}
                            className={cn(
                                'flex w-full items-center justify-between rounded-sm px-2 py-1.5 text-left text-sm',
                                index === activeIndex && 'bg-accent',
                            )}
                            onMouseEnter={() => setActiveIndex(index)}
                            onClick={() => onSelect(option.id)}
                        >
                            <span>{option.label}</span>
                            {index === activeIndex && (
                                <Check className="size-4 shrink-0 text-muted-foreground" />
                            )}
                        </button>
                    ))
                ) : (
                    <p className="px-2 py-1.5 text-sm text-muted-foreground">
                        No categories found
                    </p>
                )}
            </div>
            <div className="mt-2 flex justify-between border-t pt-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={isSaving}
                    onClick={() => onSelect(null)}
                >
                    Clear
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={isSaving}
                    onClick={onClose}
                >
                    Close
                </Button>
            </div>
        </div>
    );
}

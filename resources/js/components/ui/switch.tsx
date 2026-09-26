import * as React from "react"

import { cn } from "@/lib/utils"

function Switch({
  className,
  checked = false,
  onCheckedChange,
  ...props
}: Omit<React.ComponentProps<"button">, "role" | "value" | "onChange"> & {
  checked?: boolean
  onCheckedChange?: (checked: boolean) => void
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      data-slot="switch"
      data-state={checked ? "checked" : "unchecked"}
      onClick={() => onCheckedChange?.(!checked)}
      className={cn(
        "inline-flex h-6 w-11 shrink-0 items-center rounded-full outline-none transition-colors",
        "focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background",
        "disabled:cursor-not-allowed disabled:opacity-50",
        "data-[state=checked]:bg-brand data-[state=unchecked]:bg-input",
        className
      )}
      {...props}
    >
      <span
        data-slot="switch-thumb"
        data-state={checked ? "checked" : "unchecked"}
        className={cn(
          "pointer-events-none block size-5 rounded-full bg-background shadow-sm ring-0 transition-transform",
          "data-[state=checked]:translate-x-[22px] data-[state=unchecked]:translate-x-0.5"
        )}
      />
    </button>
  )
}

export { Switch }

"use client";

import { useEffect, useSyncExternalStore } from "react";
import { Moon, Sun } from "lucide-react";

type ThemeName = "light" | "dark";

const themeStorageKey = "razbudise-theme";
const themeListeners = new Set<() => void>();

function preferredTheme(): ThemeName {
  if (typeof window === "undefined") {
    return "light";
  }

  const storedTheme = window.localStorage.getItem(themeStorageKey);

  if (storedTheme === "light" || storedTheme === "dark") {
    return storedTheme;
  }

  return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}

function defaultTheme(): ThemeName {
  return "light";
}

function applyTheme(theme: ThemeName) {
  document.documentElement.setAttribute("data-theme", theme);
  document.documentElement.style.colorScheme = theme;
}

function subscribeToTheme(listener: () => void) {
  themeListeners.add(listener);
  const colorScheme = window.matchMedia("(prefers-color-scheme: dark)");

  window.addEventListener("storage", listener);
  colorScheme.addEventListener("change", listener);

  return () => {
    themeListeners.delete(listener);
    window.removeEventListener("storage", listener);
    colorScheme.removeEventListener("change", listener);
  };
}

function emitThemeChange() {
  for (const listener of themeListeners) {
    listener();
  }
}

export function ThemeToggle() {
  const theme = useSyncExternalStore<ThemeName>(subscribeToTheme, preferredTheme, defaultTheme);
  const nextTheme: ThemeName = theme === "light" ? "dark" : "light";
  const label = theme === "light" ? "Префрли во темен режим" : "Префрли во светол режим";

  useEffect(() => {
    applyTheme(theme);
  }, [theme]);

  function selectTheme(selectedTheme: ThemeName) {
    window.localStorage.setItem(themeStorageKey, selectedTheme);
    applyTheme(selectedTheme);
    emitThemeChange();
    window.gtag?.("event", "theme_changed", { theme: selectedTheme });
  }

  return (
    <button
      aria-label={label}
      className="header-icon-button theme-toggle"
      data-tooltip={label}
      onClick={() => selectTheme(nextTheme)}
      type="button"
    >
      {theme === "light" ? <Moon aria-hidden="true" size={20} strokeWidth={1.9} /> : <Sun aria-hidden="true" size={20} strokeWidth={1.9} />}
    </button>
  );
}

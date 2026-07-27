"use client";

import { Search } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import Link from "next/link";

export function HeaderSearch() {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === "Escape") {
        setOpen(false);
      }
    }

    function closeOnOutsideClick(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }

    document.addEventListener("keydown", closeOnEscape);
    document.addEventListener("mousedown", closeOnOutsideClick);

    return () => {
      document.removeEventListener("keydown", closeOnEscape);
      document.removeEventListener("mousedown", closeOnOutsideClick);
    };
  }, []);

  return (
    <div className={`header-search ${open ? "header-search-open" : ""}`} ref={containerRef}>
      <button
        aria-controls="header-search-panel"
        aria-expanded={open}
        aria-label="Пребарување"
        className="header-icon-button header-search-toggle"
        data-tooltip="Пребарување"
        onClick={() => setOpen((current) => !current)}
        type="button"
      >
        <Search aria-hidden="true" size={20} strokeWidth={1.9} />
      </button>
      {open ? (
        <form action="/search" className="header-search-panel" id="header-search-panel">
          <input aria-label="Барај" autoFocus name="query" placeholder="Пребарај објави..." type="search" />
          <button type="submit">Барај</button>
          <Link href="/search">Напредно</Link>
        </form>
      ) : null}
    </div>
  );
}

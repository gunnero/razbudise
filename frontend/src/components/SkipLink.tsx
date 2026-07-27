"use client";

import type { MouseEvent } from "react";

export function SkipLink() {
  function focusPrimaryContent(event: MouseEvent<HTMLAnchorElement>) {
    const main = document.querySelector("main");

    if (!main) {
      return;
    }

    event.preventDefault();
    main.id = "main-content";
    main.setAttribute("tabindex", "-1");
    main.focus();
    window.history.replaceState(null, "", "#main-content");
  }

  return (
    <a className="skip-link" href="#main-content" onClick={focusPrimaryContent}>
      Прескокни до содржината
    </a>
  );
}

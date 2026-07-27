"use client";

import { useEffect, useRef } from "react";
import { privacyConsentChangedEvent, readPrivacyConsent, type PrivacyConsent } from "@/lib/privacy/consent";

type ArticleHtmlChunkProps = {
  html: string;
};

const BROKEN_IMAGE_MESSAGE = "Сликата не е достапна од старата WordPress објава.";

function privacySafeHtml(html: string): string {
  return html.replace(/(<iframe\b[^>]*?)\ssrc=(['"])(https?:\/\/[^'"]+)\2/gi, '$1 data-privacy-src=$2$3$2');
}

export function ArticleHtmlChunk({ html }: ArticleHtmlChunkProps) {
  const rootRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const root = rootRef.current;

    if (!root) {
      return undefined;
    }

    const listeners: Array<[HTMLImageElement, () => void]> = [];
    const externalFrames = Array.from(root.querySelectorAll<HTMLIFrameElement>("iframe[data-privacy-src]"));

    const updateExternalFrames = (event?: Event) => {
      const detail = (event as CustomEvent<PrivacyConsent> | undefined)?.detail;
      const allowed = (detail ?? readPrivacyConsent())?.externalMedia === true;
      for (const frame of externalFrames) {
        const source = frame.dataset.privacySrc;
        if (allowed && source && !frame.hasAttribute("src")) frame.src = source;
        if (!allowed && frame.getAttribute("src")) frame.removeAttribute("src");
      }
    };
    updateExternalFrames();
    window.addEventListener(privacyConsentChangedEvent, updateExternalFrames);

    const markBrokenImage = (image: HTMLImageElement) => {
      image.classList.add("article-inline-image-broken");
      image.setAttribute("data-broken-image", "true");

      if (!image.getAttribute("alt")) {
        image.setAttribute("alt", "Недостапна слика");
      }

      const container = image.closest("figure, .wp-caption") ?? image.parentElement;

      if (!container || container.querySelector(".article-inline-image-warning")) {
        return;
      }

      const warning = document.createElement(container.tagName === "P" ? "span" : "p");
      warning.className = "article-inline-image-warning";
      warning.textContent = BROKEN_IMAGE_MESSAGE;
      container.append(warning);
    };

    for (const image of Array.from(root.querySelectorAll("img"))) {
      image.loading = image.loading || "lazy";
      image.decoding = "async";

      if (image.complete && image.naturalWidth === 0) {
        markBrokenImage(image);
        continue;
      }

      const listener = () => markBrokenImage(image);
      image.addEventListener("error", listener, { once: true });
      listeners.push([image, listener]);
    }

    return () => {
      window.removeEventListener(privacyConsentChangedEvent, updateExternalFrames);
      for (const [image, listener] of listeners) {
        image.removeEventListener("error", listener);
      }
    };
  }, [html]);

  return <div dangerouslySetInnerHTML={{ __html: privacySafeHtml(html) }} ref={rootRef} />;
}

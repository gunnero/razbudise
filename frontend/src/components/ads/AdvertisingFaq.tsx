"use client";

import { useState } from "react";

import { trackAdvertisingEvent } from "@/components/ads/AdvertisingAnalytics";

export type AdvertisingFaqItem = { answer: string; question: string };

export function AdvertisingFaq({ items }: { items: AdvertisingFaqItem[] }) {
  const [openIndex, setOpenIndex] = useState<number | null>(null);

  return (
    <div className="media-kit-faq-list">
      {items.map((item, index) => {
        const open = openIndex === index;
        const panelId = `advertising-faq-${index}`;
        return (
          <details key={item.question} open={open}>
            <summary
              aria-controls={panelId}
              aria-expanded={open}
              onClick={(event) => {
                event.preventDefault();
                setOpenIndex((current) => {
                  if (current !== index) trackAdvertisingEvent("ad_faq_opened", { faq_question: item.question });
                  return current === index ? null : index;
                });
              }}
            >
              {item.question}
            </summary>
            <div id={panelId}><p>{item.answer}</p></div>
          </details>
        );
      })}
    </div>
  );
}

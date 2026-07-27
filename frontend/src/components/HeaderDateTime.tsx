"use client";

import { useEffect, useState } from "react";
import { headerDateTimeParts } from "@/lib/datetime/headerDateTime";

function CalendarIcon() {
  return (
    <svg aria-hidden="true" className="header-date-time-icon" fill="none" viewBox="0 0 24 24">
      <path d="M7 3v4M17 3v4M4.5 9h15M6 5h12a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" />
    </svg>
  );
}

function ClockIcon() {
  return (
    <svg aria-hidden="true" className="header-date-time-icon" fill="none" viewBox="0 0 24 24">
      <path d="M12 6v6l4 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
    </svg>
  );
}

export function HeaderDateTime() {
  const [now, setNow] = useState(() => new Date());

  useEffect(() => {
    const timer = window.setInterval(() => {
      setNow(new Date());
    }, 1000);

    return () => window.clearInterval(timer);
  }, []);

  const parts = headerDateTimeParts(now);

  return (
    <time
      aria-label={`Тековен датум и време: ${parts.dateLabel}, ${parts.timeLabel}`}
      className="header-date-time"
      dateTime={parts.dateTime}
      suppressHydrationWarning
    >
      <span className="header-date-time-item">
        <CalendarIcon />
        <span suppressHydrationWarning>{parts.dateLabel}</span>
      </span>
      <span className="header-date-time-item">
        <ClockIcon />
        <span suppressHydrationWarning>{parts.timeLabel}</span>
      </span>
    </time>
  );
}

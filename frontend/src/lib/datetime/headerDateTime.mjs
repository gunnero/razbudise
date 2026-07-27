const defaultTimeZone = "Europe/Skopje";

function formatter(locale, options) {
  return new Intl.DateTimeFormat(locale, {
    timeZone: defaultTimeZone,
    ...options,
  });
}

function datePart(date, part, locale, options) {
  return formatter(locale, options)
    .formatToParts(date)
    .find((item) => item.type === part)?.value ?? "";
}

export function headerDateTimeParts(value = new Date()) {
  const date = value instanceof Date ? value : new Date(value);
  const safeDate = Number.isNaN(date.getTime()) ? new Date() : date;
  const weekday = formatter("mk-MK", { weekday: "long" }).format(safeDate).toLocaleLowerCase("mk-MK");
  const month = formatter("mk-MK", { month: "long" }).format(safeDate).toLocaleLowerCase("mk-MK");
  const day = datePart(safeDate, "day", "en-GB", { day: "2-digit" }).replace(/^0/, "");
  const hour = datePart(safeDate, "hour", "en-GB", {
    hour: "2-digit",
    hourCycle: "h23",
  });
  const minute = datePart(safeDate, "minute", "en-GB", {
    hour: "2-digit",
    hourCycle: "h23",
    minute: "2-digit",
  });
  const second = datePart(safeDate, "second", "en-GB", {
    hour: "2-digit",
    hourCycle: "h23",
    minute: "2-digit",
    second: "2-digit",
  });

  return {
    dateLabel: `${weekday}, ${month} ${day}`,
    dateTime: safeDate.toISOString(),
    timeLabel: `${hour}:${minute}:${second}`,
  };
}

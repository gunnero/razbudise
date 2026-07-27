const metricDefinitions = [
  ["advertising_monthly_unique_visitors", "Уникатни корисници"],
  ["advertising_monthly_page_views", "Прегледи на страници"],
  ["advertising_average_reading_time", "Просечно време на сесија"],
  ["advertising_mobile_audience_percent", "Мобилен сообраќај"],
];

function firstString(value) {
  if (Array.isArray(value)) return typeof value[0] === "string" ? value[0].trim() : "";
  return typeof value === "string" ? value.trim() : "";
}

function formatMetricValue(value) {
  if (!/^\d+$/.test(value)) return value;
  return value.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

export function verifiedAudienceMetrics(settings) {
  const verified = firstString(settings?.advertising_metrics_verified).toLowerCase();
  if (!["1", "true", "yes"].includes(verified)) return [];

  return metricDefinitions.flatMap(([key, label]) => {
    const value = firstString(settings?.[key]);
    if (!value || (/^[\d.,]+%?$/.test(value) && Number(value.replace(/[.,]/g, "").replace("%", "")) === 0)) return [];
    return [{ key, label, value: formatMetricValue(value) }];
  });
}

export function metricsReportingPeriod(settings) {
  return firstString(settings?.advertising_metrics_reporting_period) || null;
}

export function approvedClientLogos(settings) {
  const logos = settings?.advertising_approved_client_logos;
  if (!logos || Array.isArray(logos) || typeof logos !== "object") return [];

  return Object.entries(logos).flatMap(([name, path]) => {
    const cleanName = name.trim();
    const cleanPath = typeof path === "string" ? path.trim() : "";
    return cleanName && cleanPath.startsWith("/") && !cleanPath.startsWith("//")
      ? [{ name: cleanName, path: cleanPath }]
      : [];
  });
}

export function supportedDevicesLabel(products) {
  const devices = new Set();

  for (const product of products) {
    const label = String(product?.device ?? "").toLowerCase();
    if (label.includes("сите уреди")) {
      devices.add("desktop");
      devices.add("tablet");
      devices.add("mobile");
    }
    if (label.includes("компјутер")) devices.add("desktop");
    if (label.includes("таблет")) devices.add("tablet");
    if (label.includes("мобил")) devices.add("mobile");
  }

  const labels = [
    devices.has("desktop") ? "Компјутер" : null,
    devices.has("tablet") ? "таблет" : null,
    devices.has("mobile") ? "мобилен" : null,
  ].filter(Boolean);

  if (labels.length === 0) return "Уреди според позицијата";
  if (labels.length === 1) return labels[0];
  return `${labels.slice(0, -1).join(", ")} и ${labels.at(-1)}`;
}

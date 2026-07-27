import { AdZone } from "@/components/AdZone";

type AdZoneGroupProps = {
  articleId?: number;
  categoryId?: number | null;
  categorySlug?: string | null;
  className?: string;
  label?: string;
  slotKeys: string[];
};

export function AdZoneGroup({
  articleId,
  categoryId,
  categorySlug,
  className,
  label = "Реклама",
  slotKeys,
}: AdZoneGroupProps) {
  const keys = slotKeys.filter(Boolean);

  if (keys.length === 0) {
    return null;
  }

  const groupClassName = ["ad-zone-group", className].filter(Boolean).join(" ");

  return (
    <div className={groupClassName} data-slot-count={keys.length}>
      {keys.map((zoneKey) => (
        <AdZone
          articleId={articleId}
          categoryId={categoryId}
          categorySlug={categorySlug}
          key={zoneKey}
          label={label}
          zoneKey={zoneKey}
        />
      ))}
    </div>
  );
}

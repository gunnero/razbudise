import { creativeDimensionError } from "@/lib/ads/bookingPresentation";

function matchesAllowedSize(allowedSizes: number[][], width: number, height: number): boolean {
  return allowedSizes.some(([allowedWidth, allowedHeight]) => allowedWidth === width && allowedHeight === height);
}

export function AdCreativeGuidance({
  allowedSizes,
  height,
  width,
}: {
  allowedSizes: number[][];
  height: number;
  width: number;
}) {
  const requiredSize = allowedSizes[0] ?? null;
  const hasDimensions = width > 0 && height > 0;
  const hasMismatch = requiredSize && hasDimensions && !matchesAllowedSize(allowedSizes, width, height);

  return (
    <div>
      <strong>Точни димензии</strong>
      <p>
        Подгответе креатив со точна големина:
        {" "}
        {allowedSizes.length > 0
          ? allowedSizes.map(([allowedWidth, allowedHeight]) => `${allowedWidth}x${allowedHeight} px`).join(", ")
          : "димензиите ќе ги потврдиме пред објава"}
        .
      </p>
      {hasMismatch && requiredSize ? (
        <p>
          {creativeDimensionError({
            actualHeight: height,
            actualWidth: width,
            requiredHeight: requiredSize[1],
            requiredWidth: requiredSize[0],
          })}
        </p>
      ) : null}
    </div>
  );
}

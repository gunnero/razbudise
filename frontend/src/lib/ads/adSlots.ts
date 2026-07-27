import { adZoneSlotKeys as adZoneSlotKeysBase } from "./adSlots.mjs";

export const adZoneSlotKeys = adZoneSlotKeysBase as (zoneKey: string | null | undefined) => string[];

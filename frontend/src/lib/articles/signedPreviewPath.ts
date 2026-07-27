import { signedPreviewApiPath as signedPreviewApiPathBase } from "./signedPreviewPath.mjs";

export const signedPreviewApiPath = signedPreviewApiPathBase as (value?: string | null) => string | null;

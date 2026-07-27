import { headerDateTimeParts as headerDateTimePartsBase } from "./headerDateTime.mjs";

export const headerDateTimeParts = headerDateTimePartsBase as (value?: Date | string | number) => {
  dateLabel: string;
  dateTime: string;
  timeLabel: string;
};

import type { ElementType, ReactNode } from "react";

type SiteContainerProps = {
  as?: ElementType;
  children: ReactNode;
  className?: string;
  size?: "wide" | "reading";
};

export function SiteContainer({
  as: Component = "div",
  children,
  className = "",
  size = "wide",
}: SiteContainerProps) {
  return (
    <Component className={`site-container site-container-${size} ${className}`.trim()}>
      {children}
    </Component>
  );
}

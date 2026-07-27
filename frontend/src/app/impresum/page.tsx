import type { Metadata } from "next";
import { LegalPage } from "@/components/legal/LegalPage";

export const metadata: Metadata = {
  title: "Импресум",
  description: "Основни информации за издавачката и редакциската поставеност на Разбуди се.",
  alternates: {
    canonical: "/impresum",
  },
};

export default function ImpressumPage() {
  return (
    <LegalPage
      eyebrow="ИНФОРМАЦИИ"
      title="Импресум"
      description="Основни информации за издавачот, уредувањето и одговорноста на платформата."
      action={{ href: "/kontakt", label: "Контакт за исправки и права" }}
      sections={[
        {
          id: "platforma",
          title: "За платформата",
          body: [
            "Разбуди се е дигитална платформа за објавување јавни содржини од областа на животниот стил, културата, настаните, интервјуата, локалните приказни и вестите.",
          ],
        },
        {
          id: "odgovornost",
          title: "Уредување и одговорност",
          body: [
            "Редакциската содржина се подготвува со цел да биде разбирлива, корисна и јасно означена според типот на објавата.",
          ],
        },
        {
          id: "sponzorirana-sodrzina",
          title: "Спонзорирана и ПР содржина",
          body: ["Спонзорирани и ПР објави се означуваат посебно за да се разликуваат од редакциските текстови."],
        },
      ]}
    />
  );
}

import Link from "next/link";
import { SiteHeader } from "@/components/SiteHeader";

export default function NotFound() {
  return (
    <main className="site-shell">
      <SiteHeader />
      <section className="status-panel status-panel-editorial">
        <p className="kicker">404</p>
        <h1>Оваа страница не е пронајдена.</h1>
        <p>Можеби адресата е променета или текстот повеќе не е јавно достапен.</p>
        <div className="status-actions">
          <Link href="/">Почетна</Link>
          <Link href="/search">Пребарување</Link>
        </div>
      </section>
    </main>
  );
}

import Link from "next/link";
import type { TeamMemberItem } from "@/lib/api/client";
import { selectMediaUrl } from "@/lib/media/mediaUrls";
import {
  teamMemberAuthorHref,
  teamMemberBadges,
  teamMemberCardClassName,
  teamMemberInitials,
  teamMemberOrgLine,
} from "@/lib/team/teamPresentation";

const socialLabels: Record<string, string> = {
  website: "Веб",
  facebook: "Facebook",
  instagram: "Instagram",
  linkedin: "LinkedIn",
  x: "X",
};

export function TeamMemberCard({ member }: { member: TeamMemberItem }) {
  const imageUrl = selectMediaUrl(member.profile_image, { variant: "thumbnail" });
  const badges = teamMemberBadges(member);
  const authorHref = teamMemberAuthorHref(member);
  const orgLine = teamMemberOrgLine(member);
  const socialLinks = Object.entries(member.social_links ?? {})
    .filter((entry): entry is [string, string] => typeof entry[1] === "string" && entry[1].length > 0);

  return (
    <article className={teamMemberCardClassName(member)} id={member.slug}>
      <div className="avatar-frame team-member-avatar">
        {imageUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={imageUrl} alt={member.profile_image?.alt_text ?? member.name} />
        ) : (
          <span>{teamMemberInitials(member)}</span>
        )}
      </div>

      <div className="team-member-card-content">
        <p className="kicker">{member.display_title ?? "Член на тимот"}</p>
        <h3>{member.name}</h3>

        <div className="team-role-badges" aria-label="Team positions">
          {badges.map((badge) => (
            <span className={badge === "AI Crew" ? "team-role-badge team-role-badge-ai" : "team-role-badge"} key={badge}>
              {badge}
            </span>
          ))}
        </div>

        {orgLine ? <p className="team-member-org-line">{orgLine}</p> : null}
        {member.short_bio ? <p className="team-member-bio">{member.short_bio}</p> : null}

        {authorHref || socialLinks.length > 0 ? (
          <div className="team-member-links">
            {authorHref ? <Link href={authorHref}>Авторски профил</Link> : null}
            {socialLinks.map(([network, url]) => (
              <a href={url} key={network} rel="noreferrer" target="_blank">
                {socialLabels[network] ?? network}
              </a>
            ))}
          </div>
        ) : null}
      </div>
    </article>
  );
}

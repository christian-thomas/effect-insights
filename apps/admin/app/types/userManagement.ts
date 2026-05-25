export type OrganisationRole = 'owner' | 'admin' | 'viewer';

export interface OrganisationMember {
  readonly id: string;
  readonly name: string;
  readonly email: string;
  readonly role: OrganisationRole;
  readonly joinedAt: string | null;
}

export interface PendingInvitation {
  readonly id: string;
  readonly email: string;
  readonly role: OrganisationRole;
  readonly expiresAt: string | null;
  readonly acceptedAt: string | null;
  readonly createdAt: string | null;
  readonly invitedBy?: {
    readonly id: string;
    readonly name: string;
  };
}

export interface InvitationPreview {
  readonly email: string;
  readonly role: OrganisationRole;
  readonly expiresAt: string;
  readonly organisation: {
    readonly id: string;
    readonly name: string;
  };
}

export interface InviteInput {
  email: string;
  role: OrganisationRole;
}

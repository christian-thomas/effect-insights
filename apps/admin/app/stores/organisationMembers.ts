import { defineStore } from 'pinia';
import type {
  InviteInput,
  OrganisationMember,
  PendingInvitation,
} from '~/types/userManagement';

interface OrganisationMembersState {
  members: OrganisationMember[];
  invitations: PendingInvitation[];
  loading: boolean;
  saving: boolean;
  loadedOrganisationId: string | null;
}

/**
 * Superuser-only per-organisation user management:
 *   - List members + their pivot roles
 *   - List pending invitations (unaccepted, unexpired)
 *   - Create / revoke invitations
 *   - Remove a user from this organisation (detaches the pivot row, leaves
 *     the user record intact in case they belong to other orgs)
 */
export const useOrganisationMembersStore = defineStore('organisationMembers', {
  state: (): OrganisationMembersState => ({
    members: [],
    invitations: [],
    loading: false,
    saving: false,
    loadedOrganisationId: null,
  }),

  actions: {
    async fetch(organisationId: string): Promise<void> {
      this.loading = true;
      try {
        const [membersResponse, invitationsResponse] = await Promise.all([
          $api<{ data: OrganisationMember[] }>(
            `/api/organisations/${organisationId}/users`,
          ),
          $api<{ data: PendingInvitation[] }>(
            `/api/organisations/${organisationId}/invitations`,
          ),
        ]);
        this.members = membersResponse.data;
        this.invitations = invitationsResponse.data;
        this.loadedOrganisationId = organisationId;
      } finally {
        this.loading = false;
      }
    },

    async invite(organisationId: string, payload: InviteInput): Promise<void> {
      this.saving = true;
      try {
        const response = await $api<{ data: PendingInvitation }>(
          `/api/organisations/${organisationId}/invitations`,
          { method: 'POST', body: payload },
        );
        this.invitations = [response.data, ...this.invitations];
      } finally {
        this.saving = false;
      }
    },

    async revokeInvitation(organisationId: string, invitationId: string): Promise<void> {
      await $api(`/api/organisations/${organisationId}/invitations/${invitationId}`, {
        method: 'DELETE',
      });
      this.invitations = this.invitations.filter((invitation) => invitation.id !== invitationId);
    },

    async removeMember(organisationId: string, userId: string): Promise<void> {
      await $api(`/api/organisations/${organisationId}/users/${userId}`, {
        method: 'DELETE',
      });
      this.members = this.members.filter((member) => member.id !== userId);
    },
  },
});

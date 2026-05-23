export interface Organisation {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
}

export interface OrganisationInput {
  name: string;
  slug: string;
}

export interface AuthUser {
  readonly id: string;
  readonly name: string;
  readonly email: string;
  readonly isSuperuser: boolean;
}

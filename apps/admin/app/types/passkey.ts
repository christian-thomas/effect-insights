/**
 * Mirror of {@see App\Http\Resources\PasskeyResource}.
 */
export interface Passkey {
  readonly id: string;
  readonly name: string;
  readonly lastUsedAt: string | null;
  readonly createdAt: string;
}

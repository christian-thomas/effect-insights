/**
 * Tiny helpers for the WebAuthn browser API.
 *
 * The server (spatie/laravel-passkeys via web-auth/webauthn-lib) serialises
 * options as JSON with binary fields encoded as base64url strings. The
 * browser's `navigator.credentials.create()` / `.get()` need those fields
 * as `BufferSource` (ArrayBuffer/Uint8Array). And the credential the
 * browser returns has the inverse problem — binary fields need encoding
 * back to base64url before we POST them.
 *
 * These helpers handle the boundary so the controllers can hand options
 * straight through and the components stay readable.
 */

const base64urlToBuffer = (input: string): ArrayBuffer => {
  const padded = input.replace(/-/g, '+').replace(/_/g, '/');
  const padding = padded.length % 4 === 0 ? '' : '='.repeat(4 - (padded.length % 4));
  const binary = atob(padded + padding);
  const buffer = new ArrayBuffer(binary.length);
  const view = new Uint8Array(buffer);
  for (let index = 0; index < binary.length; index++) {
    view[index] = binary.charCodeAt(index);
  }
  return buffer;
};

const bytesToBase64url = (input: ArrayBuffer): string => {
  const bytes = new Uint8Array(input);
  let binary = '';
  for (let index = 0; index < bytes.length; index++) {
    binary += String.fromCharCode(bytes[index]!);
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

interface ServerCreateOptions {
  challenge: string;
  rp: { name: string; id: string };
  user: { id: string; name: string; displayName: string };
  pubKeyCredParams: Array<{ type: PublicKeyCredentialType; alg: number }>;
  authenticatorSelection?: AuthenticatorSelectionCriteria;
  attestation?: AttestationConveyancePreference;
  timeout?: number;
  excludeCredentials?: Array<{ type: PublicKeyCredentialType; id: string }>;
}

interface ServerRequestOptions {
  challenge: string;
  rpId?: string;
  timeout?: number;
  allowCredentials?: Array<{ type: PublicKeyCredentialType; id: string }>;
  userVerification?: UserVerificationRequirement;
}

/**
 * Decode the JSON the server gave us into a PublicKeyCredentialCreationOptions
 * the browser will accept.
 */
export const prepareCreateOptions = (options: ServerCreateOptions): PublicKeyCredentialCreationOptions => {
  return {
    ...options,
    challenge: base64urlToBuffer(options.challenge),
    user: {
      ...options.user,
      id: base64urlToBuffer(options.user.id),
    },
    excludeCredentials: options.excludeCredentials?.map((credential) => ({
      ...credential,
      id: base64urlToBuffer(credential.id),
    })),
  };
};

export const prepareRequestOptions = (options: ServerRequestOptions): PublicKeyCredentialRequestOptions => {
  return {
    ...options,
    challenge: base64urlToBuffer(options.challenge),
    allowCredentials: options.allowCredentials?.map((credential) => ({
      ...credential,
      id: base64urlToBuffer(credential.id),
    })),
  };
};

/**
 * Encode the browser's response into the same JSON shape the spatie
 * StorePasskeyAction / FindPasskeyToAuthenticateAction expect — which
 * is the WebAuthn-spec "PublicKeyCredential JSON" representation.
 */
export const credentialToJson = (credential: PublicKeyCredential): string => {
  const response = credential.response;
  const base = {
    id: credential.id,
    rawId: bytesToBase64url(credential.rawId),
    type: credential.type,
    authenticatorAttachment: credential.authenticatorAttachment,
    clientExtensionResults: credential.getClientExtensionResults(),
  };

  if (response instanceof AuthenticatorAttestationResponse) {
    return JSON.stringify({
      ...base,
      response: {
        clientDataJSON: bytesToBase64url(response.clientDataJSON),
        attestationObject: bytesToBase64url(response.attestationObject),
        // Optional — newer authenticators expose these for additional context.
        transports: response.getTransports?.() ?? [],
        publicKey: response.getPublicKey
          ? response.getPublicKey() && bytesToBase64url(response.getPublicKey() as ArrayBuffer)
          : undefined,
        publicKeyAlgorithm: response.getPublicKeyAlgorithm?.(),
        authenticatorData: response.getAuthenticatorData
          ? bytesToBase64url(response.getAuthenticatorData())
          : undefined,
      },
    });
  }

  if (response instanceof AuthenticatorAssertionResponse) {
    return JSON.stringify({
      ...base,
      response: {
        clientDataJSON: bytesToBase64url(response.clientDataJSON),
        authenticatorData: bytesToBase64url(response.authenticatorData),
        signature: bytesToBase64url(response.signature),
        userHandle: response.userHandle ? bytesToBase64url(response.userHandle) : null,
      },
    });
  }

  throw new Error('Unrecognised PublicKeyCredential response shape.');
};

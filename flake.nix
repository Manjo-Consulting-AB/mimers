# Utvecklingsmiljön: samma PHP som CI (.github/workflows/ci.yml). Node är
# 22 och inte CI:s 20, som är end-of-life och borta ur nixpkgs.
# Aktiveras av direnv genom .envrc, eller för hand med `nix develop`.
{
  description = "Mimers – utvecklingsmiljö";

  inputs.nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";

  outputs = { self, nixpkgs }:
    let
      system = "x86_64-linux";
      pkgs = nixpkgs.legacyPackages.${system};

      # composer.json kräver ^8.4. Standardtilläggen täcker CI:s lista
      # (fileinfo, mbstring, pdo_sqlite, sqlite3, zip, pdo_mysql); redis
      # för att .env.example kör phpredis. Ingen xdebug – den gör sviten
      # långsam och behövs bara vid stegvis felsökning.
      php = pkgs.php84.buildEnv {
        extensions = { enabled, all }: enabled ++ [ all.redis ];
        extraConfig = "memory_limit = 1G";
      };
    in
    {
      devShells.${system}.default = pkgs.mkShell {
        packages = [
          php
          php.packages.composer
          pkgs.nodejs_22
        ];
      };
    };
}

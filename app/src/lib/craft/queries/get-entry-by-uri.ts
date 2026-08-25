import {
  craftPreviewQuery,
  craftQuery,
  normalizeCraftCacheTags,
} from "@/lib/craft/client";
import { EntryByUriQuery } from "@/queries";
import { cache } from "react";

export const HOME_URI = "home";

const entryTagsForUri = (uri: string) => {
  const tags = [
    "craft",
    "craft:entries",
    "craft:sections",
    "craft:section:reusableSections",
    `craft:entry-uri:${uri}`,
  ];

  if (uri === HOME_URI) {
    tags.push("craft:pages", `craft:page-uri:${HOME_URI}`);
  }

  return normalizeCraftCacheTags(tags);
};

export const getEntryByUri = (uri: string) => {
  return craftQuery(
    EntryByUriQuery,
    { uri: [uri] },
    {
      tags: entryTagsForUri(uri),
      revalidate: false,
    },
  );
};

export const getPreviewEntryByUri = cache((uri: string, previewToken: string) => {
  return craftPreviewQuery(
    EntryByUriQuery,
    { uri: [uri] },
    {
      previewToken,
    },
  );
});

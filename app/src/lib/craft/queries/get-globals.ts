import { craftQuery } from "@/lib/craft/client";
import { GlobalsQuery } from "@/queries";

export const getGlobals = () => {
  return craftQuery(
    GlobalsQuery,
    {},
    {
      tags: [
        "craft",
        "craft:globals",
        "craft:global:footer",
        "craft:global:legal",
        "craft:global:errorPage",
        "craft:global:seo",
      ],
      revalidate: false,
    },
  );
};
